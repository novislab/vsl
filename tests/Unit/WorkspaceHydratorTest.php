<?php

use App\Services\WorkspaceHydrator;
use Tests\TestCase;

uses(TestCase::class);

it('rewrites existing APP_URL or appends it when missing', function () {
    $withExisting = "APP_NAME=Novis\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\n";
    expect(WorkspaceHydrator::rewriteEnvAppUrl($withExisting, 'https://feat.1-2-3-4.sslip.io'))
        ->toBe("APP_NAME=Novis\nAPP_URL=https://feat.1-2-3-4.sslip.io\nDB_CONNECTION=sqlite\n");

    $withoutAppUrl = "APP_NAME=Novis\nDB_CONNECTION=sqlite\n";
    expect(WorkspaceHydrator::rewriteEnvAppUrl($withoutAppUrl, 'https://feat.1-2-3-4.sslip.io'))
        ->toBe("APP_NAME=Novis\nDB_CONNECTION=sqlite\nAPP_URL=https://feat.1-2-3-4.sslip.io\n");

    expect(WorkspaceHydrator::rewriteEnvAppUrl('', 'https://feat.1-2-3-4.sslip.io'))
        ->toBe("APP_URL=https://feat.1-2-3-4.sslip.io\n");
});

it('hydrates vendor and node_modules via hardlinks, deep-copies storage, and syncs .env', function () {
    $base = sys_get_temp_dir().'/vsl-hydrate-'.uniqid('', true);
    $parent = $base.'/parent';
    $worktree = $base.'/worktree';

    mkdir($parent.'/vendor/pkg', 0755, true);
    mkdir($parent.'/node_modules/pkg', 0755, true);
    mkdir($parent.'/storage/logs', 0755, true);
    mkdir($worktree, 0755, true);

    file_put_contents($parent.'/vendor/pkg/autoload.php', '<?php return 1;');
    file_put_contents($parent.'/node_modules/pkg/index.js', 'module.exports = 1;');
    file_put_contents($parent.'/storage/logs/laravel.log', 'parent-log');
    file_put_contents($parent.'/.env', "APP_NAME=Demo\nAPP_URL=http://127.0.0.1\n");

    try {
        (new WorkspaceHydrator)->hydrate($parent, $worktree, 'https://demo.1-2-3-4.sslip.io');

        expect(is_file($worktree.'/vendor/pkg/autoload.php'))->toBeTrue()
            ->and(is_file($worktree.'/node_modules/pkg/index.js'))->toBeTrue()
            ->and(is_file($worktree.'/storage/logs/laravel.log'))->toBeTrue()
            ->and(file_get_contents($worktree.'/.env'))
            ->toBe("APP_NAME=Demo\nAPP_URL=https://demo.1-2-3-4.sslip.io\n");

        // vendor & node_modules share inodes (hardlinked)
        expect(fileinode($worktree.'/vendor/pkg/autoload.php'))
            ->toBe(fileinode($parent.'/vendor/pkg/autoload.php'))
            ->and(fileinode($worktree.'/node_modules/pkg/index.js'))
            ->toBe(fileinode($parent.'/node_modules/pkg/index.js'));

        // storage is deep-copied (distinct inode)
        expect(fileinode($worktree.'/storage/logs/laravel.log'))
            ->not->toBe(fileinode($parent.'/storage/logs/laravel.log'));
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
});
