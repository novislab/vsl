<?php

use App\Services\ProcessManager;
use App\Services\ViteManager;
use App\Support\VslPaths;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('parses custom entry directories, base path, and hmr.path from user vite.config', function () {
    $config = <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    base: '/custom/',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'src/client/main.tsx'],
            refresh: true,
        }),
    ],
    server: {
        hmr: {
            path: '/vite-ws',
        },
    },
});
JS;

    [$paths, $hmrPath] = ViteManager::parseViteConfigHints($config);

    expect($paths)->toBe(['/resources/*', '/src/*', '/custom/*'])
        ->and($hmrPath)->toBe('/vite-ws');
});

it('detects vite config, wraps it without touching git files, and dispatches bun dev in background', function () {
    $base = sys_get_temp_dir().'/vsl-vite-'.uniqid('', true);
    $wt = $base.'/wt';
    mkdir($wt.'/node_modules/.bin', 0755, true);
    mkdir($wt.'/public', 0755, true);
    file_put_contents($wt.'/node_modules/.bin/vite', "#!/usr/bin/env sh\n");
    chmod($wt.'/node_modules/.bin/vite', 0755);
    file_put_contents($wt.'/package.json', json_encode(['scripts' => ['dev' => 'vite'], 'devDependencies' => ['vite' => '^6.0.0']]));
    file_put_contents($wt.'/vite.config.ts', "export default { plugins: [] };\n");
    file_put_contents($wt.'/public/hot', 'https://feat-ui.test');

    Process::fake([
        '*' => Process::result(output: "61234\n"),
    ]);

    try {
        $paths = new VslPaths($base.'/.vsl');
        $manager = new ViteManager($paths, static fn (int $port): bool => $port !== 5173);

        $port = $manager->allocatePort();
        expect($port)->toBe(5174);

        $job = $manager->dispatch($wt, 'feat-ui', 'feat-ui.test', $port);
        expect($job)->not->toBeNull()
            ->and($job['port'])->toBe(5174)
            ->and($job['pid'])->toBe(61234)
            ->and($job['hmrPath'])->toBe('/__vite_hmr')
            ->and(is_file($base.'/.vsl/vite/feat-ui.config.ts'))->toBeTrue();

        $wrapperContents = file_get_contents($base.'/.vsl/vite/feat-ui.config.ts');
        expect($wrapperContents)
            ->toContain("file://{$wt}/vite.config.ts")
            ->toContain("isSecure ? 'wss' : 'ws'")
            ->toContain('/__vite_hmr')
            ->toContain('isSecure ? 443 : 80');

        Process::assertRan(function ($process): bool {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'setsid env')
                && str_contains($cmd, 'VSL_PREVIEW_DOMAIN=')
                && str_contains($cmd, 'VSL_PREVIEW_URL=')
                && str_contains($cmd, 'VSL_VITE_PORT=5174')
                && str_contains($cmd, 'feat-ui-vite.log');
        });

        // Cleanup removes public/hot, wrapper config, and log
        $manager->cleanup($wt, 'feat-ui', null, null, new ProcessManager);
        expect(is_file($wt.'/public/hot'))->toBeFalse()
            ->and(is_file($base.'/.vsl/vite/feat-ui.config.ts'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
