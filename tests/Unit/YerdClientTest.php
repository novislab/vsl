<?php

use App\Services\YerdClient;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('resolves TLD from yerd status --json and builds .test preview URLs', function () {
    $prevEnv = getenv('VSL_YERD_TLD');
    putenv('VSL_YERD_TLD');
    unset($_ENV['VSL_YERD_TLD'], $_SERVER['VSL_YERD_TLD']);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'type' => 'status',
            'report' => ['tld' => 'test'],
        ])),
    ]);

    try {
        $client = new YerdClient('/usr/bin/yerd');

        expect($client->buildPreviewUrl('feat-api'))->toBe([
            'tld' => 'test',
            'domain' => 'feat-api.test',
            'url' => 'https://feat-api.test',
        ]);

        expect($client->buildPreviewUrl('feat-api', secure: false))->toBe([
            'tld' => 'test',
            'domain' => 'feat-api.test',
            'url' => 'http://feat-api.test',
        ]);

        // VSL_YERD_TLD overrides yerd status
        $_ENV['VSL_YERD_TLD'] = '.localtest';
        expect($client->buildPreviewUrl('feat-api'))->toBe([
            'tld' => 'localtest',
            'domain' => 'feat-api.localtest',
            'url' => 'https://feat-api.localtest',
        ]);
    } finally {
        unset($_ENV['VSL_YERD_TLD'], $_SERVER['VSL_YERD_TLD']);
        if ($prevEnv !== false) {
            putenv("VSL_YERD_TLD={$prevEnv}");
        }
    }
});

it('detects parent repository PHP version from yerd sites --json', function () {
    $base = sys_get_temp_dir().'/vsl-yerd-parent-'.uniqid('', true);
    mkdir($base.'/novira', 0755, true);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'type' => 'sites',
            'sites' => [
                [
                    'name' => 'novira',
                    'document_root' => realpath($base.'/novira'),
                    'php' => '8.4',
                    'secure' => true,
                ],
            ],
        ])),
    ]);

    try {
        $client = new YerdClient('/usr/bin/yerd');

        expect($client->detectParentPhpVersion($base.'/novira'))->toBe('8.4')
            ->and($client->detectParentPhpVersion($base.'/unknown'))->toBeNull();
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('links, pins PHP, secures, and attaches Vite path proxies in Yerd', function () {
    Process::fake();

    $client = new YerdClient('/usr/bin/yerd');
    $prefixes = $client->registerSite(
        slug: 'feat-hmr',
        worktreePath: '/tmp/wt-feat-hmr',
        secure: true,
        phpVersion: '8.4',
        vitePort: 5173,
        vitePaths: ['/src/*', '/resources/*'],
        hmrPath: '/custom-hmr',
    );

    expect($prefixes)->toBe([
        '/@vite',
        '/@fs',
        '/@id',
        '/@react-refresh',
        '/__vite_ping',
        '/resources',
        '/node_modules',
        '/src',
        '/custom-hmr',
    ]);

    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'link', 'feat-hmr', '/tmp/wt-feat-hmr']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'use', 'feat-hmr', '8.4']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'secure', 'feat-hmr']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'proxy', 'add', 'feat-hmr', '/@vite', 'http://127.0.0.1:5173']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'proxy', 'add', 'feat-hmr', '/src', 'http://127.0.0.1:5173']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'proxy', 'add', 'feat-hmr', '/custom-hmr', 'http://127.0.0.1:5173']);
});

it('removes Vite path proxies and unlinks sites idempotently', function () {
    Process::fake(function ($process) {
        if ($process->command === ['/usr/bin/yerd', 'unlink', 'missing-site']) {
            return Process::result(output: '', errorOutput: 'site not found', exitCode: 1);
        }

        return Process::result(output: 'ok');
    });

    $client = new YerdClient('/usr/bin/yerd');
    $client->unregisterSite('missing-site', ['/@vite', '/__vite_hmr']);

    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'proxy', 'remove', 'missing-site', '/@vite']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'proxy', 'remove', 'missing-site', '/__vite_hmr']);
    Process::assertRan(fn ($p) => $p->command === ['/usr/bin/yerd', 'unlink', 'missing-site']);
});
