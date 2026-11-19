<?php

use App\Services\OctaneManager;
use App\Support\VslPaths;

it('skips reserved ports from state.json and busy TCP ports in 9100-9900', function () {
    $busyPorts = [9101, 9103];
    $manager = new OctaneManager(
        paths: new VslPaths(sys_get_temp_dir().'/vsl-oct-'.uniqid('', true)),
        portChecker: static fn (int $port): bool => ! in_array($port, $busyPorts, true),
    );

    // 9100 and 9102 are reserved in state, 9101 and 9103 are busy -> picks 9104
    $allocated = $manager->allocatePort([9100, 9102]);
    expect($allocated)->toBe(9104);

    // Preferred port is reused when free and not reserved
    expect($manager->allocatePort([9100], 9150))->toBe(9150);

    // Preferred port falls back to next free when busy
    expect($manager->allocatePort([9100], 9101))->toBe(9102);
});

it('throws when no TCP ports are available in 9100-9900', function () {
    $manager = new OctaneManager(
        paths: new VslPaths(sys_get_temp_dir().'/vsl-oct-'.uniqid('', true)),
        portChecker: static fn (int $port): bool => false,
    );

    expect(fn () => $manager->allocatePort())
        ->toThrow(RuntimeException::class, '9100-9900');
});

it('builds instant dev reload flags with --watch when chokidar exists or --max-requests=1 without chokidar', function () {
    $base = sys_get_temp_dir().'/vsl-oct-flags-'.uniqid('', true);
    mkdir($base.'/without-chokidar', 0755, true);
    mkdir($base.'/with-chokidar/node_modules/chokidar', 0755, true);

    try {
        [$flagsNoChokidar, $strategyNoChokidar] = OctaneManager::buildOctaneFlags($base.'/without-chokidar', 9100, true);
        expect($strategyNoChokidar)->toBe('stateless-dev')
            ->and($flagsNoChokidar)->toContain('--max-requests=1')
            ->and($flagsNoChokidar)->not->toContain('--watch');

        [$flagsChokidar, $strategyChokidar] = OctaneManager::buildOctaneFlags($base.'/with-chokidar', 9100, true);
        expect($strategyChokidar)->toBe('watch')
            ->and($flagsChokidar)->toContain('--watch')
            ->and($flagsChokidar)->toContain('--max-requests=500');

        [$flagsProd, $strategyProd] = OctaneManager::buildOctaneFlags($base.'/with-chokidar', 9100, false);
        expect($strategyProd)->toBe('prod')
            ->and($flagsProd)->not->toContain('--watch');
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
