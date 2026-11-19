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
        domain: 'feat-auth.203-0-113-10.sslip.io',
        url: 'https://feat-auth.203-0-113-10.sslip.io',
        port: 9100,
        pid: 4242,
        pgid: 4242,
        path: realpath($worktreePath) ?: $worktreePath,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T14:00:00+00:00',
    );

    try {
        $store->put($state);

        expect(is_file($paths->stateFile()))->toBeTrue()
            ->and(is_file($paths->stateLockFile()))->toBeTrue()
            ->and($store->get('feat-auth')?->toArray())->toBe($state->toArray())
            ->and($store->reservedPorts())->toBe([9100]);

        // Lookup by slug, branch, normalized slug, and path/subdir
        expect($store->findByTarget('feat-auth')?->slug)->toBe('feat-auth')
            ->and($store->findByTarget('feat/Auth')?->slug)->toBe('feat-auth')
            ->and($store->findByTarget('FEAT/AUTH')?->slug)->toBe('feat-auth')
            ->and($store->findByTarget($worktreePath)?->slug)->toBe('feat-auth')
            ->and($store->findByPath($worktreePath.'/app')?->slug)->toBe('feat-auth');

        // Atomic mutate
        $updatedCount = $store->mutate(function (array $states): array {
            $states['feat-auth'] = $states['feat-auth']->withWorker(9105, 5555, 5555);

            return [$states, count($states)];
        });

        expect($updatedCount)->toBe(1)
            ->and($store->get('feat-auth')?->port)->toBe(9105)
            ->and($store->get('feat-auth')?->pid)->toBe(5555);

        // Remove
        expect($store->remove('feat-auth'))->toBeTrue()
            ->and($store->remove('feat-auth'))->toBeFalse()
            ->and($store->all())->toBe([]);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
