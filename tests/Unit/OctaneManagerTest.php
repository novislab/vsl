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

it('selects octane:start when installed, frankenphp php-server otherwise, or artisan serve fallback', function () {
    $base = sys_get_temp_dir().'/vsl-srv-cmd-'.uniqid('', true);
    $octaneApp = $base.'/with-octane';
    $plainApp = $base.'/plain-laravel';

    mkdir($octaneApp.'/vendor/laravel/octane', 0755, true);
    mkdir($plainApp.'/public', 0755, true);

    try {
        [$cmd1, $strat1] = OctaneManager::buildServerCommand($octaneApp, 9100, true, '/usr/local/bin/frankenphp');
        expect($cmd1)->toStartWith('php artisan octane:start')
            ->and($strat1)->toBe('stateless-dev');

        [$cmd2, $strat2] = OctaneManager::buildServerCommand($plainApp, 9102, true, '/usr/local/bin/frankenphp');
        expect($cmd2)->toContain('CADDY_ADMIN=off')
            ->and($cmd2)->toContain('frankenphp')
            ->and($cmd2)->toContain('php-server --listen 127.0.0.1:9102')
            ->and($strat2)->toBe('frankenphp-dev');

        [$cmd3, $strat3] = OctaneManager::buildServerCommand($plainApp, 9103, true, null);
        expect($cmd3)->toBe('php artisan serve --host=127.0.0.1 --port=9103 --no-reload')
            ->and($strat3)->toBe('artisan-serve');
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('never kills the caller process or process group in stop()', function () {
    $manager = new OctaneManager;
    $myPid = getmypid();
    $myPgid = posix_getpgid($myPid);

    $manager->stop($myPid, $myPgid);
    expect($manager->isAlive($myPid))->toBeTrue();
});
