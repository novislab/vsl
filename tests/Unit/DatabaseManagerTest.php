<?php

use App\Data\WorktreeState;
use App\Services\DatabaseManager;
use App\Support\VslPaths;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('parses .env files and normalizes supported database drivers', function () {
    $env = <<<'ENV'
# Comment line
APP_NAME="Novis Lab"
DB_CONNECTION=mysql
DB_HOST=127.0.0.1 # inline comment
DB_PORT='3306'
DB_DATABASE=novis_main
DB_USERNAME=forge
DB_PASSWORD="s3cr3t#pass"
ENV;

    $parsed = DatabaseManager::parseEnv($env);

    expect($parsed)->toBe([
        'APP_NAME' => 'Novis Lab',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'novis_main',
        'DB_USERNAME' => 'forge',
        'DB_PASSWORD' => 's3cr3t#pass',
    ]);

    expect(DatabaseManager::normalizeDriver('mysql'))->toBe('mysql')
        ->and(DatabaseManager::normalizeDriver('mariadb'))->toBe('mysql')
        ->and(DatabaseManager::normalizeDriver('pgsql'))->toBe('pgsql')
        ->and(DatabaseManager::normalizeDriver('postgres'))->toBe('pgsql')
        ->and(DatabaseManager::normalizeDriver('sqlite'))->toBe('sqlite')
        ->and(DatabaseManager::normalizeDriver(''))->toBeNull()
        ->and(DatabaseManager::normalizeDriver('redis'))->toBeNull();
});

it('generates safe database identifiers capped at 63 characters', function () {
    $name = DatabaseManager::databaseNameForSlug('feat-add-oauth-login-and-very-long-branch-name-that-exceeds-sixty-three-chars');

    expect(strlen($name))->toBeLessThanOrEqual(63)
        ->and($name)->toStartWith('vsl_')
        ->and($name)->toMatch('/^[a-z0-9_]+$/')
        ->and(str_ends_with($name, '_'))->toBeFalse();
});

it('scans .env and rewrites DB_DATABASE for mysql, pgsql, and sqlite', function () {
    $base = sys_get_temp_dir().'/vsl-db-env-'.uniqid('', true);
    mkdir($base, 0755, true);

    try {
        $manager = new DatabaseManager(new VslPaths($base.'/.vsl'));

        // MySQL
        file_put_contents($base.'/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=main_app\nDB_USERNAME=sail\nDB_PASSWORD=secret\n");
        $mysql = $manager->prepareEnv($base, 'feat-auth');
        expect($mysql)->toBe([
            'connection' => 'mysql',
            'database' => 'vsl_feat_auth',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'sail',
            'password' => 'secret',
        ]);
        expect(file_get_contents($base.'/.env'))->toContain('DB_DATABASE=vsl_feat_auth');

        // PostgreSQL
        file_put_contents($base.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=10.0.0.2\nDB_PORT=5432\nDB_DATABASE=main_pg\nDB_USERNAME=pguser\nDB_PASSWORD=pgpass\n");
        $pgsql = $manager->prepareEnv($base, 'fix-billing');
        expect($pgsql['connection'])->toBe('pgsql')
            ->and($pgsql['database'])->toBe('vsl_fix_billing')
            ->and(file_get_contents($base.'/.env'))->toContain('DB_DATABASE=vsl_fix_billing');

        // SQLite
        file_put_contents($base.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/tmp/old.sqlite\n");
        $sqlite = $manager->prepareEnv($base, 'feat-lite');
        expect($sqlite['connection'])->toBe('sqlite')
            ->and($sqlite['database'])->toBe($base.'/database/vsl_feat_lite.sqlite')
            ->and(file_get_contents($base.'/.env'))->toContain('DB_DATABASE='.$base.'/database/vsl_feat_lite.sqlite');

        // SQLite :memory: skipped
        file_put_contents($base.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n");
        expect($manager->prepareEnv($base, 'mem'))->toBeNull();
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('dispatches detached background provision and teardown jobs without blocking', function () {
    $base = sys_get_temp_dir().'/vsl-db-dispatch-'.uniqid('', true);
    mkdir($base.'/wt', 0755, true);

    Process::fake([
        '*' => Process::result(output: "54321\n"),
    ]);

    try {
        $manager = new DatabaseManager(new VslPaths($base.'/.vsl'));

        $prov = $manager->dispatchProvision($base.'/wt', 'feat-bg', seed: true);
        expect($prov['pid'])->toBe(54321)
            ->and($prov['log'])->toBe($base.'/.vsl/logs/feat-bg-db.log');

        Process::assertRan(function ($process): bool {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'setsid ')
                && str_contains($cmd, 'db:provision')
                && str_contains($cmd, 'feat-bg')
                && str_contains($cmd, 'feat-bg-db.log');
        });

        $state = new WorktreeState(
            slug: 'feat-bg',
            branch: 'feat/bg',
            domain: 'feat-bg.1-2-3-4.sslip.io',
            url: 'https://feat-bg.1-2-3-4.sslip.io',
            port: 9100,
            pid: 1111,
            pgid: 1111,
            path: $base.'/wt',
            parent_path: $base.'/parent',
            created_at: '2026-11-19T14:00:00+00:00',
            db_connection: 'mysql',
            db_name: 'vsl_feat_bg',
            db_pid: 54321,
            db_pgid: 54321,
        );

        $down = $manager->dispatchTeardown($state);
        expect($down['pid'])->toBe(54321);

        Process::assertRan(function ($process): bool {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'setsid ')
                && str_contains($cmd, 'db:teardown')
                && str_contains($cmd, '--connection=mysql')
                && str_contains($cmd, '--database=vsl_feat_bg');
        });
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});

it('creates, migrates, seeds, and drops mysql and pgsql databases and cleans sqlite files', function () {
    $base = sys_get_temp_dir().'/vsl-db-run-'.uniqid('', true);
    mkdir($base.'/wt-mysql', 0755, true);
    mkdir($base.'/wt-pgsql', 0755, true);
    mkdir($base.'/parent', 0755, true);

    file_put_contents($base.'/wt-mysql/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=vsl_wt_mysql\nDB_USERNAME=root\nDB_PASSWORD=pw\n");
    file_put_contents($base.'/wt-pgsql/.env', "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=vsl_wt_pgsql\nDB_USERNAME=postgres\nDB_PASSWORD=pgpw\n");
    file_put_contents($base.'/parent/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=main\nDB_USERNAME=root\nDB_PASSWORD=pw\n");

    Process::fake(function ($process) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return match ($cmd) {
            'which mysql' => Process::result(output: "/usr/bin/mysql\n"),
            'which psql' => Process::result(output: "/usr/bin/psql\n"),
            default => Process::result(output: ''),
        };
    });

    try {
        $manager = new DatabaseManager(new VslPaths($base.'/.vsl'));

        // MySQL provision + teardown (even after worktree .env removed, falls back to parent .env)
        $manager->provision($base.'/wt-mysql', seed: true);

        Process::assertRan(fn ($p) => is_array($p->command)
            && ($p->command[0] ?? '') === 'mysql'
            && str_contains(implode(' ', $p->command), 'CREATE DATABASE IF NOT EXISTS `vsl_wt_mysql`'));

        Process::assertRan(fn ($p) => $p->command === ['php', 'artisan', 'migrate', '--force', '--seed']);

        @unlink($base.'/wt-mysql/.env');
        $manager->teardown('mysql', 'vsl_wt_mysql', $base.'/wt-mysql/.env', $base.'/parent/.env');

        Process::assertRan(fn ($p) => is_array($p->command)
            && ($p->command[0] ?? '') === 'mysql'
            && str_contains(implode(' ', $p->command), 'DROP DATABASE IF EXISTS `vsl_wt_mysql`'));

        // PostgreSQL provision + teardown
        $manager->provision($base.'/wt-pgsql', seed: false);

        Process::assertRan(fn ($p) => is_array($p->command)
            && ($p->command[0] ?? '') === 'psql'
            && str_contains(implode(' ', $p->command), 'CREATE DATABASE "vsl_wt_pgsql"'));

        Process::assertRan(fn ($p) => $p->command === ['php', 'artisan', 'migrate', '--force']);

        $manager->teardown('pgsql', 'vsl_wt_pgsql', $base.'/wt-pgsql/.env');

        Process::assertRan(fn ($p) => is_array($p->command)
            && ($p->command[0] ?? '') === 'psql'
            && str_contains(implode(' ', $p->command), 'DROP DATABASE IF EXISTS "vsl_wt_pgsql"'));

        // SQLite create & unlink
        $sqlitePath = $base.'/wt-sqlite/database/vsl_lite.sqlite';
        $manager->createDatabase('sqlite', $sqlitePath, '', 0, '', '');
        file_put_contents($sqlitePath.'-wal', 'wal');
        expect(is_file($sqlitePath))->toBeTrue();

        $manager->teardown('sqlite', $sqlitePath);
        expect(is_file($sqlitePath))->toBeFalse()
            ->and(is_file($sqlitePath.'-wal'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
