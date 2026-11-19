<?php

use App\Commands\Concerns\InteractsWithVslUi;
use App\Commands\InstallServiceCommand;
use App\Data\WorktreeState;
use App\Services\DatabaseManager;
use App\Services\IpResolver;
use App\Services\OctaneManager;
use App\Services\StateStore;
use App\Support\VslPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

it('formats elapsed time in ms under 1s and seconds at or above 1s', function () {
    expect(InteractsWithVslUi::formatElapsedNs(284_000_000))->toBe('284ms')
        ->and(InteractsWithVslUi::formatElapsedNs(999_000_000))->toBe('999ms')
        ->and(InteractsWithVslUi::formatElapsedNs(1_240_000_000))->toBe('1.24s');
});

it('runs vsl up, persists state, renders QR/URL, and tears down cleanly with vsl down', function () {
    $base = sys_get_temp_dir().'/vsl-feat-'.uniqid('', true);
    $vslHome = $base.'/vsl-home';
    $mainRepo = $base.'/repo';
    $worktreeDir = $base.'/worktrees/feat-preview';

    mkdir($mainRepo.'/vendor/acme', 0755, true);
    mkdir($mainRepo.'/storage/logs', 0755, true);
    mkdir(dirname($worktreeDir), 0755, true);

    $run = function (string $cwd, string $cmd): void {
        exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("Command failed ({$cmd}): ".implode("\n", $out));
        }
    };

    $run($mainRepo, 'git init -b main');
    $run($mainRepo, 'git config user.email "test@novislab.dev"');
    $run($mainRepo, 'git config user.name "VSL Test"');
    file_put_contents($mainRepo.'/README.md', "# Repo\n");
    file_put_contents($mainRepo.'/vendor/acme/lib.php', '<?php');
    file_put_contents($mainRepo.'/storage/logs/app.log', 'log');
    file_put_contents($mainRepo.'/.env', "APP_NAME=Novis\nAPP_URL=http://localhost\n");
    $run($mainRepo, 'git add README.md && git commit -m "init"');
    $run($mainRepo, 'git worktree add -b feat/QR-Preview '.escapeshellarg($worktreeDir));

    $paths = new VslPaths($vslHome);
    $this->app->instance(VslPaths::class, $paths);
    $this->app->instance(StateStore::class, new StateStore($paths));
    $this->app->instance(IpResolver::class, new IpResolver($paths));

    $_ENV['VSL_PUBLIC_IP'] = '34.210.12.99';

    Http::fake([
        'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['listen' => [':80', ':443']], 200),
        'http://127.0.0.1:2019/id/vsl-feat-qr-preview' => Http::response([], 404),
        'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
    ]);

    $this->mock(OctaneManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('allocatePort')->once()->andReturn(9105);
        $mock->shouldReceive('spawnDetached')->once()->andReturn([
            'port' => 9105,
            'pid' => 77889,
            'pgid' => 77889,
            'log' => '/tmp/feat-qr-preview.log',
            'reload_strategy' => 'stateless-dev',
        ]);
        $mock->shouldReceive('waitUntilListening')->once()->with(9105, 77889, 77889, '/tmp/feat-qr-preview.log');
        $mock->shouldReceive('stop')->once()->with(77889, 77889);
        $mock->shouldReceive('isAlive')->andReturn(true);
    });

    $this->mock(DatabaseManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('prepareEnv')->once()->andReturn(null);
        $mock->shouldNotReceive('dispatchProvision');
        $mock->shouldNotReceive('dispatchTeardown');
    });

    try {
        // vsl up (with QR output)
        $this->artisan('up', ['--path' => $worktreeDir])
            ->expectsOutputToContain('https://feat-qr-preview.34-210-12-99.sslip.io')
            ->expectsOutputToContain('Ready in ')
            ->expectsOutputToContain('█')
            ->assertSuccessful();

        $store = new StateStore($paths);
        $saved = $store->get('feat-qr-preview');

        expect($saved)->not->toBeNull()
            ->and($saved->port)->toBe(9105)
            ->and($saved->pid)->toBe(77889)
            ->and($saved->url)->toBe('https://feat-qr-preview.34-210-12-99.sslip.io')
            ->and(file_get_contents($worktreeDir.'/.env'))
            ->toContain('APP_URL=https://feat-qr-preview.34-210-12-99.sslip.io');

        // vsl list --json
        $exitCode = Artisan::call('list', ['--json' => true]);
        $listJson = json_decode(Artisan::output(), true);
        expect($exitCode)->toBe(0)
            ->and($listJson)->toBeArray()->toHaveCount(1)
            ->and($listJson[0]['slug'])->toBe('feat-qr-preview')
            ->and($listJson[0]['status'])->toBe('RUNNING');

        // vsl down --path
        $this->artisan('down', ['--path' => $worktreeDir])
            ->expectsOutputToContain('Stopped: feat-qr-preview')
            ->expectsOutputToContain('Ready in ')
            ->assertSuccessful();

        // Worktree directory on disk must NOT be deleted by vsl down
        expect(is_dir($worktreeDir))->toBeTrue()
            ->and($store->all())->toBe([]);

        // Explicit missing target fails cleanly
        $this->artisan('down', ['target' => 'non-existent-slug'])
            ->expectsOutputToContain('No active worktree preview found')
            ->assertFailed();
    } finally {
        unset($_ENV['VSL_PUBLIC_IP']);
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('updates .env DB_DATABASE and dispatches background DB provision on up and DB teardown on down', function () {
    $base = sys_get_temp_dir().'/vsl-db-cmd-'.uniqid('', true);
    $vslHome = $base.'/vsl-home';
    $mainRepo = $base.'/repo';
    $worktreeDir = $base.'/worktrees/feat-db';

    mkdir(dirname($worktreeDir), 0755, true);
    mkdir($mainRepo, 0755, true);

    $run = function (string $cwd, string $cmd): void {
        exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("Command failed ({$cmd}): ".implode("\n", $out));
        }
    };

    $run($mainRepo, 'git init -b main');
    $run($mainRepo, 'git config user.email "test@novislab.dev"');
    $run($mainRepo, 'git config user.name "VSL Test"');
    file_put_contents($mainRepo.'/README.md', "# Repo\n");
    file_put_contents($mainRepo.'/.env', "APP_NAME=Novis\nAPP_URL=http://localhost\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=novis_main\nDB_USERNAME=root\nDB_PASSWORD=secret\n");
    $run($mainRepo, 'git add README.md && git commit -m "init"');
    $run($mainRepo, 'git worktree add -b feat/db-isolation '.escapeshellarg($worktreeDir));

    $paths = new VslPaths($vslHome);
    $store = new StateStore($paths);
    $this->app->instance(VslPaths::class, $paths);
    $this->app->instance(StateStore::class, $store);
    $this->app->instance(IpResolver::class, new IpResolver($paths));

    $_ENV['VSL_PUBLIC_IP'] = '198.51.100.20';

    Http::fake([
        'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['listen' => [':80', ':443']], 200),
        'http://127.0.0.1:2019/id/vsl-*' => Http::response([], 200),
        'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
    ]);

    $this->mock(OctaneManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('allocatePort')->once()->andReturn(9120);
        $mock->shouldReceive('spawnDetached')->once()->andReturn([
            'port' => 9120,
            'pid' => 70001,
            'pgid' => 70001,
            'log' => '/tmp/feat-db-isolation.log',
            'reload_strategy' => 'stateless-dev',
        ]);
        $mock->shouldReceive('waitUntilListening')->once()->with(9120, 70001, 70001, '/tmp/feat-db-isolation.log');
        $mock->shouldReceive('stop')->once()->with(88001, 88001);
        $mock->shouldReceive('stop')->once()->with(70001, 70001);
        $mock->shouldReceive('isAlive')->andReturn(true);
    });

    $this->mock(DatabaseManager::class, function (MockInterface $mock) use ($worktreeDir, $paths): void {
        $mock->shouldReceive('prepareEnv')
            ->once()
            ->andReturnUsing(fn () => (new DatabaseManager($paths))->prepareEnv($worktreeDir, 'feat-db-isolation'));

        $mock->shouldReceive('dispatchProvision')
            ->once()
            ->with(realpath($worktreeDir), 'feat-db-isolation', true)
            ->andReturn(['pid' => 88001, 'pgid' => 88001, 'log' => $paths->dbLogFile('feat-db-isolation')]);

        $mock->shouldReceive('dispatchTeardown')
            ->once()
            ->withArgs(fn (WorktreeState $s) => $s->slug === 'feat-db-isolation'
                && $s->db_connection === 'mysql'
                && $s->db_name === 'vsl_feat_db_isolation')
            ->andReturn(['pid' => 88002, 'pgid' => 88002]);
    });

    try {
        $exitUp = Artisan::call('up', ['--path' => $worktreeDir, '--no-qr' => true]);
        $upOut = Artisan::output();

        expect($exitUp)->toBe(0)
            ->and($upOut)->toContain('vsl_feat_db_isolation')
            ->and($upOut)->toContain('creating + migrating + seeding in background')
            ->and(file_get_contents($worktreeDir.'/.env'))->toContain('DB_DATABASE=vsl_feat_db_isolation');

        $saved = $store->get('feat-db-isolation');
        expect($saved)->not->toBeNull()
            ->and($saved->db_connection)->toBe('mysql')
            ->and($saved->db_name)->toBe('vsl_feat_db_isolation')
            ->and($saved->db_pid)->toBe(88001);

        $exitDown = Artisan::call('down', ['--path' => $worktreeDir]);
        $downOut = Artisan::output();

        expect($exitDown)->toBe(0)
            ->and($downOut)->toContain('Dropping vsl_feat_db_isolation (mysql) in background')
            ->and($store->get('feat-db-isolation'))->toBeNull();
    } finally {
        unset($_ENV['VSL_PUBLIC_IP']);
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('resurrects dead workers, prunes missing directories, and re-registers Caddy routes', function () {
    $base = sys_get_temp_dir().'/vsl-resurrect-'.uniqid('', true);
    $vslHome = $base.'/vsl-home';
    $liveDir = $base.'/wt-live';
    $deadDir = $base.'/wt-dead';
    $missingDir = $base.'/wt-deleted';

    mkdir($liveDir, 0755, true);
    mkdir($deadDir, 0755, true);

    $paths = new VslPaths($vslHome);
    $store = new StateStore($paths);

    $store->put(new WorktreeState(
        slug: 'wt-live',
        branch: 'feat/live',
        domain: 'wt-live.1-2-3-4.sslip.io',
        url: 'https://wt-live.1-2-3-4.sslip.io',
        port: 9100,
        pid: 11111,
        pgid: 11111,
        path: $liveDir,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T12:00:00+00:00',
    ));

    $store->put(new WorktreeState(
        slug: 'wt-dead',
        branch: 'feat/dead',
        domain: 'wt-dead.1-2-3-4.sslip.io',
        url: 'https://wt-dead.1-2-3-4.sslip.io',
        port: 9101,
        pid: 22222,
        pgid: 22222,
        path: $deadDir,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T12:00:00+00:00',
    ));

    $store->put(new WorktreeState(
        slug: 'wt-deleted',
        branch: 'feat/deleted',
        domain: 'wt-deleted.1-2-3-4.sslip.io',
        url: 'https://wt-deleted.1-2-3-4.sslip.io',
        port: 9102,
        pid: 33333,
        pgid: 33333,
        path: $missingDir,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T12:00:00+00:00',
    ));

    $this->app->instance(VslPaths::class, $paths);
    $this->app->instance(StateStore::class, $store);

    Http::fake([
        'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['listen' => [':80', ':443']], 200),
        'http://127.0.0.1:2019/id/vsl-*' => Http::response([], 200),
        'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
    ]);

    $this->mock(OctaneManager::class, function (MockInterface $mock) use ($deadDir): void {
        $mock->shouldReceive('isAlive')->with(11111)->andReturn(true);
        $mock->shouldReceive('isAlive')->with(22222)->andReturn(false);
        $mock->shouldReceive('allocatePort')->once()->with([9100], 9101)->andReturn(9101);
        $mock->shouldReceive('start')->once()->with($deadDir, 'wt-dead', 9101)->andReturn([
            'port' => 9101,
            'pid' => 44444,
            'pgid' => 44444,
        ]);
    });

    try {
        $exitCode = Artisan::call('resurrect');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Restored routes:   2')
            ->and($output)->toContain('Restarted workers: 1')
            ->and($output)->toContain('Pruned missing:    1')
            ->and($output)->toContain('Ready in ')
            ->and($store->get('wt-deleted'))->toBeNull()
            ->and($store->get('wt-dead')?->pid)->toBe(44444)
            ->and($store->get('wt-live')?->pid)->toBe(11111);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('generates the systemd unit file and runs systemctl enable for install-service', function () {
    $unit = InstallServiceCommand::buildUnitFile('ubuntu', '/usr/local/bin/vsl');

    expect($unit)
        ->toContain('After=network-online.target caddy.service')
        ->toContain('User=ubuntu')
        ->toContain('Environment=HOME=/home/ubuntu')
        ->toContain('ExecStart=/usr/local/bin/vsl resurrect')
        ->toContain('WantedBy=multi-user.target');

    $this->artisan('install-service', [
        '--user' => 'ubuntu',
        '--binary' => '/usr/local/bin/vsl',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('ExecStart=/usr/local/bin/vsl resurrect')
        ->assertSuccessful();

    Process::fake();

    $this->artisan('install-service', [
        '--user' => 'ubuntu',
        '--binary' => '/usr/local/bin/vsl',
    ])
        ->expectsOutputToContain('Reboot persistence installed and enabled.')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'systemctl daemon-reload'
    ));

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'systemctl enable caddy.service vsl-resurrect.service'
    ));
});

it('supports vsl up --no-qr --json and vsl list --prune', function () {
    $base = sys_get_temp_dir().'/vsl-json-prune-'.uniqid('', true);
    $vslHome = $base.'/vsl-home';
    $mainRepo = $base.'/repo';
    $worktreeDir = $base.'/worktrees/feat-json';

    mkdir(dirname($worktreeDir), 0755, true);
    mkdir($mainRepo, 0755, true);

    $run = function (string $cwd, string $cmd): void {
        exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("Command failed ({$cmd}): ".implode("\n", $out));
        }
    };

    $run($mainRepo, 'git init -b main');
    $run($mainRepo, 'git config user.email "test@novislab.dev"');
    $run($mainRepo, 'git config user.name "VSL Test"');
    file_put_contents($mainRepo.'/README.md', "# Repo\n");
    $run($mainRepo, 'git add README.md && git commit -m "init"');
    $run($mainRepo, 'git worktree add -b feat/json-out '.escapeshellarg($worktreeDir));

    $paths = new VslPaths($vslHome);
    $store = new StateStore($paths);
    $this->app->instance(VslPaths::class, $paths);
    $this->app->instance(StateStore::class, $store);
    $this->app->instance(IpResolver::class, new IpResolver($paths));

    $_ENV['VSL_PUBLIC_IP'] = '192.0.2.42';

    Http::fake([
        'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['listen' => [':80', ':443']], 200),
        'http://127.0.0.1:2019/id/vsl-*' => Http::response([], 200),
        'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
    ]);

    $this->mock(OctaneManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('allocatePort')->once()->andReturn(9110);
        $mock->shouldReceive('spawnDetached')->once()->andReturn([
            'port' => 9110,
            'pid' => 65001,
            'pgid' => 65001,
            'log' => '/tmp/feat-json-out.log',
            'reload_strategy' => 'stateless-dev',
        ]);
        $mock->shouldReceive('waitUntilListening')->once()->with(9110, 65001, 65001, '/tmp/feat-json-out.log');
        $mock->shouldReceive('isAlive')->with(65001)->andReturn(true);
        $mock->shouldReceive('isAlive')->with(99999)->andReturn(false);
    });

    try {
        $exitUp = Artisan::call('up', ['--path' => $worktreeDir, '--no-qr' => true, '--json' => true]);
        $upJson = json_decode(Artisan::output(), true);

        expect($exitUp)->toBe(0)
            ->and($upJson)->toBeArray()
            ->and($upJson['slug'])->toBe('feat-json-out')
            ->and($upJson['url'])->toBe('https://feat-json-out.192-0-2-42.sslip.io')
            ->and($upJson['port'])->toBe(9110)
            ->and($upJson['elapsed'])->toMatch('/^\d+(ms|\.\d{2}s)$/');

        // Add a dead entry and verify list --prune removes only the dead entry
        $store->put(new WorktreeState(
            slug: 'stale-branch',
            branch: 'stale/branch',
            domain: 'stale-branch.192-0-2-42.sslip.io',
            url: 'https://stale-branch.192-0-2-42.sslip.io',
            port: 9111,
            pid: 99999,
            pgid: 99999,
            path: $base.'/missing',
            parent_path: $mainRepo,
            created_at: '2026-11-19T12:00:00+00:00',
        ));

        $exitList = Artisan::call('list', ['--prune' => true]);
        $listOutput = Artisan::output();

        expect($exitList)->toBe(0)
            ->and($listOutput)->toContain('feat-json-out')
            ->and($listOutput)->toContain('RUNNING')
            ->and($listOutput)->toContain('Pruned 1 dead worktree(s).')
            ->and($store->get('stale-branch'))->toBeNull()
            ->and($store->get('feat-json-out'))->not->toBeNull();
    } finally {
        unset($_ENV['VSL_PUBLIC_IP']);
        exec('rm -rf '.escapeshellarg($base));
    }
});
