<?php

use App\Commands\Concerns\InteractsWithVslUi;
use App\Commands\InstallServiceCommand;
use App\Data\WorktreeState;
use App\Services\DatabaseManager;
use App\Services\ProcessManager;
use App\Services\StateStore;
use App\Services\ViteManager;
use App\Services\YerdClient;
use App\Support\VslPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

it('formats elapsed time in ms under 1s and seconds at or above 1s', function () {
    expect(InteractsWithVslUi::formatElapsedNs(284_000_000))->toBe('284ms')
        ->and(InteractsWithVslUi::formatElapsedNs(999_000_000))->toBe('999ms')
        ->and(InteractsWithVslUi::formatElapsedNs(1_240_000_000))->toBe('1.24s');
});

it('runs vsl up with Yerd .test, persists state, renders QR/URL, and tears down cleanly with vsl down', function () {
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

    $this->mock(YerdClient::class, function (MockInterface $mock) use ($worktreeDir): void {
        $mock->shouldReceive('buildPreviewUrl')
            ->once()
            ->with('feat-qr-preview', true)
            ->andReturn([
                'tld' => 'test',
                'domain' => 'feat-qr-preview.test',
                'url' => 'https://feat-qr-preview.test',
            ]);
        $mock->shouldReceive('detectParentPhpVersion')->once()->andReturn('8.5');
        $mock->shouldReceive('registerSite')
            ->once()
            ->with('feat-qr-preview', realpath($worktreeDir), true, '8.5', null, [], null)
            ->andReturn([]);
        $mock->shouldReceive('listSites')
            ->once()
            ->andReturn([
                'feat-qr-preview' => ['name' => 'feat-qr-preview', 'php' => '8.5', 'secure' => true],
            ]);
        $mock->shouldReceive('unregisterSite')
            ->once()
            ->with('feat-qr-preview', []);
    });

    $this->mock(DatabaseManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('prepareEnv')->once()->andReturn(null);
        $mock->shouldNotReceive('dispatchProvision');
        $mock->shouldNotReceive('dispatchTeardown');
    });

    try {
        // vsl up (with QR output)
        $this->artisan('up', ['--path' => $worktreeDir])
            ->expectsOutputToContain('https://feat-qr-preview.test')
            ->expectsOutputToContain('Ready in ')
            ->expectsOutputToContain('█')
            ->assertSuccessful();

        $store = new StateStore($paths);
        $saved = $store->get('feat-qr-preview');

        expect($saved)->not->toBeNull()
            ->and($saved->domain)->toBe('feat-qr-preview.test')
            ->and($saved->url)->toBe('https://feat-qr-preview.test')
            ->and($saved->php_version)->toBe('8.5')
            ->and(file_get_contents($worktreeDir.'/.env'))
            ->toContain('APP_URL=https://feat-qr-preview.test');

        // vsl list --json
        $exitCode = Artisan::call('list', ['--json' => true]);
        $listJson = json_decode(Artisan::output(), true);
        expect($exitCode)->toBe(0)
            ->and($listJson)->toBeArray()->toHaveCount(1)
            ->and($listJson[0]['slug'])->toBe('feat-qr-preview')
            ->and($listJson[0]['status'])->toBe('LINKED');

        // vsl down --path
        $this->artisan('down', ['--path' => $worktreeDir])
            ->expectsOutputToContain('Unlinking feat-qr-preview.test from Yerd')
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

    $this->mock(YerdClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('buildPreviewUrl')->once()->andReturn([
            'tld' => 'test',
            'domain' => 'feat-db-isolation.test',
            'url' => 'https://feat-db-isolation.test',
        ]);
        $mock->shouldReceive('detectParentPhpVersion')->once()->andReturn(null);
        $mock->shouldReceive('registerSite')->once()->andReturn([]);
        $mock->shouldReceive('unregisterSite')->once()->with('feat-db-isolation', []);
    });

    $this->mock(ProcessManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stop')->once()->with(88001, 88001);
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
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('resurrects Yerd sites, restarts dead Vite workers, and prunes missing directories', function () {
    $base = sys_get_temp_dir().'/vsl-resurrect-'.uniqid('', true);
    $vslHome = $base.'/vsl-home';
    $liveDir = $base.'/wt-live';
    $deadViteDir = $base.'/wt-dead-vite';
    $missingDir = $base.'/wt-deleted';

    mkdir($liveDir, 0755, true);
    mkdir($deadViteDir, 0755, true);

    $paths = new VslPaths($vslHome);
    $store = new StateStore($paths);

    $store->put(new WorktreeState(
        slug: 'wt-live',
        branch: 'feat/live',
        domain: 'wt-live.test',
        url: 'https://wt-live.test',
        path: $liveDir,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T12:00:00+00:00',
        secure: true,
        php_version: '8.5',
    ));

    $store->put(new WorktreeState(
        slug: 'wt-dead-vite',
        branch: 'feat/dead-vite',
        domain: 'wt-dead-vite.test',
        url: 'https://wt-dead-vite.test',
        path: $deadViteDir,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T12:00:00+00:00',
        secure: true,
        vite_port: 5173,
        vite_pid: 22222,
        vite_pgid: 22222,
        vite_proxy_paths: ['/@vite'],
    ));

    $store->put(new WorktreeState(
        slug: 'wt-deleted',
        branch: 'feat/deleted',
        domain: 'wt-deleted.test',
        url: 'https://wt-deleted.test',
        path: $missingDir,
        parent_path: $base.'/repo',
        created_at: '2026-11-19T12:00:00+00:00',
    ));

    $this->app->instance(VslPaths::class, $paths);
    $this->app->instance(StateStore::class, $store);

    $this->mock(ProcessManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('isAlive')->with(22222)->andReturn(false);
    });

    $this->mock(ViteManager::class, function (MockInterface $mock) use ($liveDir, $deadViteDir): void {
        $mock->shouldReceive('detectConfig')->with($liveDir)->andReturn(null);
        $mock->shouldReceive('detectConfig')->with($deadViteDir)->andReturn([
            'configFile' => $deadViteDir.'/vite.config.ts',
            'isTypeScript' => true,
            'customPaths' => ['/resources/*'],
            'hmrPath' => null,
        ]);
        $mock->shouldReceive('allocatePort')->once()->with([], 5173)->andReturn(5173);
        $mock->shouldReceive('dispatch')->once()->with($deadViteDir, 'wt-dead-vite', 'wt-dead-vite.test', 5173, true)->andReturn([
            'port' => 5173,
            'pid' => 44444,
            'pgid' => 44444,
            'log' => '/tmp/wt-dead-vite-vite.log',
            'customPaths' => ['/resources/*'],
            'hmrPath' => '/__vite_hmr',
        ]);
    });

    $this->mock(YerdClient::class, function (MockInterface $mock) use ($liveDir, $deadViteDir): void {
        $mock->shouldReceive('unregisterSite')->once()->with('wt-deleted', []);
        $mock->shouldReceive('registerSite')->once()->with('wt-live', $liveDir, true, '8.5', null, [], null)->andReturn([]);
        $mock->shouldReceive('registerSite')->once()->with('wt-dead-vite', $deadViteDir, true, null, 5173, ['/resources/*'], '/__vite_hmr')->andReturn(['/@vite', '/__vite_hmr']);
    });

    try {
        $exitCode = Artisan::call('resurrect');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Restored sites:    2')
            ->and($output)->toContain('Restarted Vite:    1')
            ->and($output)->toContain('Pruned missing:    1')
            ->and($output)->toContain('Ready in ')
            ->and($store->get('wt-deleted'))->toBeNull()
            ->and($store->get('wt-dead-vite')?->vite_pid)->toBe(44444)
            ->and($store->get('wt-dead-vite')?->vite_proxy_paths)->toBe(['/@vite', '/__vite_hmr']);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('generates the systemd unit file and runs systemctl enable for install-service', function () {
    $unit = InstallServiceCommand::buildUnitFile('ubuntu', '/usr/local/bin/vsl');

    expect($unit)
        ->toContain('After=network-online.target')
        ->not->toContain('caddy.service')
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
        'systemctl enable vsl-resurrect.service'
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

    $this->mock(YerdClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('buildPreviewUrl')->once()->andReturn([
            'tld' => 'test',
            'domain' => 'feat-json-out.test',
            'url' => 'https://feat-json-out.test',
        ]);
        $mock->shouldReceive('detectParentPhpVersion')->once()->andReturn('8.5');
        $mock->shouldReceive('registerSite')->once()->andReturn([]);
        $mock->shouldReceive('listSites')->once()->andReturn([
            'feat-json-out' => ['name' => 'feat-json-out', 'php' => '8.5', 'secure' => true],
        ]);
        $mock->shouldReceive('unregisterSite')->once()->with('stale-branch', []);
    });

    try {
        $exitUp = Artisan::call('up', ['--path' => $worktreeDir, '--no-qr' => true, '--json' => true]);
        $upJson = json_decode(Artisan::output(), true);

        expect($exitUp)->toBe(0)
            ->and($upJson)->toBeArray()
            ->and($upJson['slug'])->toBe('feat-json-out')
            ->and($upJson['domain'])->toBe('feat-json-out.test')
            ->and($upJson['url'])->toBe('https://feat-json-out.test')
            ->and($upJson['elapsed'])->toMatch('/^\d+(ms|\.\d{2}s)$/');

        // Add a missing/unlinked entry and verify list --prune removes only the stale entry
        $store->put(new WorktreeState(
            slug: 'stale-branch',
            branch: 'stale/branch',
            domain: 'stale-branch.test',
            url: 'https://stale-branch.test',
            path: $base.'/missing',
            parent_path: $mainRepo,
            created_at: '2026-11-19T12:00:00+00:00',
        ));

        $exitList = Artisan::call('list', ['--prune' => true]);
        $listOutput = Artisan::output();

        expect($exitList)->toBe(0)
            ->and($listOutput)->toContain('feat-json-out')
            ->and($listOutput)->toContain('LINKED')
            ->and($listOutput)->toContain('Pruned 1 dead worktree(s).')
            ->and($store->get('stale-branch'))->toBeNull()
            ->and($store->get('feat-json-out'))->not->toBeNull();
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
