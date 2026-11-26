<?php

use App\Data\WorktreeState;
use App\Services\StateStore;
use App\Support\VslPaths;

it('persists, reads, finds, mutates, and removes state atomically with flock', function () {
    $base = sys_get_temp_dir().'/vsl-state-'.uniqid('', true);
    $paths = new VslPaths($base);
    $store = new StateStore($paths);

    $worktreePath = $base.'/worktrees/feat-auth';
    mkdir($worktreePath.'/app', 0755, true);

    $state = new WorktreeState(
        slug: 'feat-auth',
        branch: 'feat/Auth',
        domain: 'feat-auth.test',
        url: 'https://feat-auth.test',
        path: realpath($worktreePath) ?: $worktreePath,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T14:00:00+00:00',
        secure: true,
        php_version: '8.5',
        vite_port: 5173,
        vite_pid: 4242,
        vite_pgid: 4242,
        vite_proxy_paths: ['/@vite', '/__vite_hmr'],
    );

    try {
        $store->put($state);

        expect(is_file($paths->stateFile()))->toBeTrue()
            ->and(is_file($paths->stateLockFile()))->toBeTrue()
            ->and($store->get('feat-auth')?->toArray())->toBe($state->toArray())
            ->and($store->reservedVitePorts())->toBe([5173]);

        // Lookup by slug, branch, normalized slug, and path/subdir
        expect($store->findByTarget('feat-auth')?->slug)->toBe('feat-auth')
            ->and($store->findByTarget('feat/Auth')?->slug)->toBe('feat-auth')
            ->and($store->findByTarget('FEAT/AUTH')?->slug)->toBe('feat-auth')
            ->and($store->findByTarget($worktreePath)?->slug)->toBe('feat-auth')
            ->and($store->findByPath($worktreePath.'/app')?->slug)->toBe('feat-auth');

        // Atomic mutate
        $updatedCount = $store->mutate(function (array $states): array {
            $states['feat-auth'] = $states['feat-auth']->withVite(5174, 5555, 5555, ['/@vite']);

            return [$states, count($states)];
        });

        expect($updatedCount)->toBe(1)
            ->and($store->get('feat-auth')?->vite_port)->toBe(5174)
            ->and($store->get('feat-auth')?->vite_pid)->toBe(5555)
            ->and($store->get('feat-auth')?->vite_proxy_paths)->toBe(['/@vite']);

        // Remove
        expect($store->remove('feat-auth'))->toBeTrue()
            ->and($store->remove('feat-auth'))->toBeFalse()
            ->and($store->all())->toBe([]);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
