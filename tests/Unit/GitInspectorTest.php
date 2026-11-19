<?php

use App\Services\GitInspector;
use Tests\TestCase;

uses(TestCase::class);

function removeDirRecursive(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.'/'.$item;
        if (is_dir($path) && ! is_link($path)) {
            removeDirRecursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

it('sanitizes branch names into RFC 1123 DNS-safe slugs', function (string $input, string $expected) {
    expect(GitInspector::slugify($input))->toBe($expected);
})->with([
    'slashes and uppercase' => ['Feature/Add_OAuth2.Login', 'feature-add-oauth2-login'],
    'leading and trailing symbols' => ['---feat/test---', 'feat-test'],
    'consecutive special chars' => ['fix//bug___#123!!', 'fix-bug-123'],
    'empty fallback' => ['---///---', 'worktree'],
    '63 char cap without trailing hyphen' => [
        str_repeat('a', 62).'-'.str_repeat('b', 20),
        str_repeat('a', 62),
    ],
    'exact 63 chars' => [
        str_repeat('x', 70),
        str_repeat('x', 63),
    ],
]);

it('rejects running inside the primary repository and inspects linked worktrees', function () {
    $base = sys_get_temp_dir().'/vsl-git-test-'.uniqid('', true);
    $mainRepo = $base.'/main-repo';
    $worktreeDir = $base.'/wt-feature';

    mkdir($mainRepo, 0755, true);

    try {
        $run = function (string $cwd, string $cmd): void {
            exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);
            if ($code !== 0) {
                throw new RuntimeException("Command failed ({$cmd}): ".implode("\n", $out));
            }
        };

        $run($mainRepo, 'git init -b main');
        $run($mainRepo, 'git config user.email "test@novislab.dev"');
        $run($mainRepo, 'git config user.name "VSL Test"');
        file_put_contents($mainRepo.'/README.md', "# Test\n");
        $run($mainRepo, 'git add README.md && git commit -m "init"');
        $run($mainRepo, 'git worktree add -b feat/My_Cool_Branch '.escapeshellarg($worktreeDir));

        $inspector = new GitInspector;

        // Primary repo must be rejected
        expect(fn () => $inspector->inspect($mainRepo))
            ->toThrow(RuntimeException::class, 'primary repository');

        // Linked worktree succeeds and resolves parent, branch, and slug
        $info = $inspector->inspect($worktreeDir);

        expect($info->worktreePath)->toBe(realpath($worktreeDir))
            ->and($info->parentPath)->toBe(realpath($mainRepo))
            ->and($info->branch)->toBe('feat/My_Cool_Branch')
            ->and($info->slug)->toBe('feat-my-cool-branch');

        // Detached HEAD falls back to worktree directory basename
        $run($worktreeDir, 'git checkout --detach HEAD');
        $detached = $inspector->inspect($worktreeDir);
        expect($detached->branch)->toBe('wt-feature')
            ->and($detached->slug)->toBe('wt-feature');
    } finally {
        removeDirRecursive($base);
    }
});
