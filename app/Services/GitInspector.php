<?php

namespace App\Services;

use App\Data\WorktreeInfo;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GitInspector
{
    /**
     * Inspect the target directory in a single git rev-parse invocation,
     * verify it is a linked Git worktree, and resolve parent repo, branch, and slug.
     */
    public function inspect(string $path): WorktreeInfo
    {
        if (! is_dir($path)) {
            throw new RuntimeException("Worktree path does not exist: {$path}");
        }

        $realTarget = realpath($path) ?: $path;

        $result = Process::path($realTarget)->run([
            'git',
            'rev-parse',
            '--is-inside-work-tree',
            '--show-toplevel',
            '--git-dir',
            '--git-common-dir',
            '--abbrev-ref',
            'HEAD',
        ]);

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("Directory is not inside a Git working tree ({$realTarget}): {$error}");
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result->output())) ?: [];
        if (count($lines) < 5 || trim($lines[0]) !== 'true') {
            throw new RuntimeException("Directory is not inside a Git working tree: {$realTarget}");
        }

        $worktreePath = realpath(trim($lines[1])) ?: trim($lines[1]);
        $gitDir = $this->resolveGitPath($realTarget, trim($lines[2]));
        $gitCommonDir = $this->resolveGitPath($realTarget, trim($lines[3]));
        $branchRaw = trim($lines[4]);

        if ($gitDir === $gitCommonDir) {
            throw new RuntimeException(
                "Refusing to run in the primary repository ({$worktreePath}). Run inside a linked Git worktree."
            );
        }

        $parentPath = dirname($gitCommonDir);
        if (! is_dir($parentPath)) {
            throw new RuntimeException("Unable to resolve parent repository directory from: {$gitCommonDir}");
        }

        $branch = ($branchRaw === '' || $branchRaw === 'HEAD')
            ? basename($worktreePath)
            : $branchRaw;

        return new WorktreeInfo(
            worktreePath: $worktreePath,
            parentPath: $parentPath,
            branch: $branch,
            slug: self::slugify($branch),
        );
    }

    /**
     * Normalize a branch or directory name into an RFC 1123 DNS-safe label (max 63 chars).
     */
    public static function slugify(string $value): string
    {
        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        if (strlen($normalized) > 63) {
            $normalized = rtrim(substr($normalized, 0, 63), '-');
        }

        return $normalized !== '' ? $normalized : 'worktree';
    }

    private function resolveGitPath(string $baseDir, string $gitPath): string
    {
        $candidate = str_starts_with($gitPath, '/')
            ? $gitPath
            : rtrim($baseDir, '/').'/'.$gitPath;

        return realpath($candidate) ?: $candidate;
    }
}
