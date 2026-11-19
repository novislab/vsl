<?php

namespace App\Services;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WorkspaceHydrator
{
    /**
     * Hydrate dependencies, isolated storage, and .env in the target worktree.
     */
    public function hydrate(string $parentPath, string $worktreePath, string $appUrl): void
    {
        $tasks = [];

        foreach (['vendor', 'node_modules'] as $dir) {
            $source = rtrim($parentPath, '/').'/'.$dir;
            $target = rtrim($worktreePath, '/').'/'.$dir;

            if (is_dir($source) && ! file_exists($target)) {
                $tasks[$dir] = ['cp', '-al', $source, $target];
            }
        }

        $parentStorage = rtrim($parentPath, '/').'/storage';
        $worktreeStorage = rtrim($worktreePath, '/').'/storage';

        if (is_dir($parentStorage)) {
            if (! file_exists($worktreeStorage)) {
                $tasks['storage'] = ['cp', '-a', $parentStorage, $worktreeStorage];
            } else {
                $tasks['storage'] = ['cp', '-a', $parentStorage.'/.', $worktreeStorage.'/'];
            }
        }

        if ($tasks !== []) {
            $results = Process::pool(function (Pool $pool) use ($tasks): void {
                foreach ($tasks as $key => $command) {
                    $pool->as((string) $key)->command($command);
                }
            })->start()->wait();

            foreach ($tasks as $key => $command) {
                $result = $results[$key];
                if (! $result->successful()) {
                    $error = trim($result->errorOutput()) ?: trim($result->output());
                    throw new RuntimeException(
                        sprintf('Failed to hydrate %s (%s): %s', $key, implode(' ', $command), $error)
                    );
                }
            }
        }

        $this->syncEnvFile($parentPath, $worktreePath, $appUrl);
    }

    /**
     * Copy .env (or .env.example) if absent and rewrite APP_URL to the live preview URL.
     */
    public function syncEnvFile(string $parentPath, string $worktreePath, string $appUrl): void
    {
        $worktreeEnv = rtrim($worktreePath, '/').'/.env';

        if (! is_file($worktreeEnv)) {
            $candidates = [
                rtrim($parentPath, '/').'/.env',
                rtrim($parentPath, '/').'/.env.example',
                rtrim($worktreePath, '/').'/.env.example',
            ];

            $source = null;
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $source = $candidate;
                    break;
                }
            }

            if ($source !== null) {
                if (! @copy($source, $worktreeEnv)) {
                    throw new RuntimeException("Failed to copy {$source} to {$worktreeEnv}");
                }
            } else {
                file_put_contents($worktreeEnv, '');
            }
        }

        $contents = file_get_contents($worktreeEnv);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$worktreeEnv}");
        }

        $updated = self::rewriteEnvAppUrl($contents, $appUrl);

        if (file_put_contents($worktreeEnv, $updated) === false) {
            throw new RuntimeException("Unable to write {$worktreeEnv}");
        }
    }

    /**
     * Rewrite an existing APP_URL= line in .env content or append it if absent.
     */
    public static function rewriteEnvAppUrl(string $envContent, string $appUrl): string
    {
        $line = 'APP_URL='.$appUrl;

        if (preg_match('/^APP_URL=.*$/m', $envContent) === 1) {
            return preg_replace('/^APP_URL=.*$/m', $line, $envContent, 1) ?? $envContent;
        }

        $trimmed = rtrim($envContent, "\r\n");

        if ($trimmed === '') {
            return $line."\n";
        }

        return $trimmed."\n".$line."\n";
    }
}
