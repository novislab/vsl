<?php

namespace App\Services;

use App\Data\WorktreeState;
use App\Support\VslPaths;
use Closure;
use RuntimeException;

class StateStore
{
    public function __construct(
        private readonly VslPaths $paths = new VslPaths,
    ) {}

    /**
     * Return all persisted worktree states keyed by slug.
     *
     * @return array<string, WorktreeState>
     */
    public function all(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            return $this->readUnlocked();
        });
    }

    public function get(string $slug): ?WorktreeState
    {
        $states = $this->all();

        return $states[$slug] ?? null;
    }

    /**
     * Find a worktree state by slug, branch, normalized slug, or filesystem path.
     */
    public function findByTarget(string $target): ?WorktreeState
    {
        $states = $this->all();

        if (isset($states[$target])) {
            return $states[$target];
        }

        foreach ($states as $state) {
            if ($state->branch === $target) {
                return $state;
            }
        }

        $normalizedTarget = $this->normalizePath($target);
        foreach ($states as $state) {
            if ($this->normalizePath($state->path) === $normalizedTarget) {
                return $state;
            }
        }

        $slugged = GitInspector::slugify($target);

        return $states[$slugged] ?? null;
    }

    /**
     * Find a worktree state matching the given directory (or a subdirectory inside it).
     */
    public function findByPath(string $path): ?WorktreeState
    {
        $normalized = $this->normalizePath($path);
        $states = $this->all();

        foreach ($states as $state) {
            $statePath = $this->normalizePath($state->path);
            if ($statePath === $normalized || str_starts_with($normalized, $statePath.'/')) {
                return $state;
            }
        }

        return null;
    }

    public function put(WorktreeState $state): void
    {
        $this->withLock(LOCK_EX, function () use ($state): void {
            $states = $this->readUnlocked();
            $states[$state->slug] = $state;
            $this->writeUnlocked($states);
        });
    }

    public function remove(string $slug): bool
    {
        return $this->withLock(LOCK_EX, function () use ($slug): bool {
            $states = $this->readUnlocked();
            if (! isset($states[$slug])) {
                return false;
            }

            unset($states[$slug]);
            $this->writeUnlocked($states);

            return true;
        });
    }

    /**
     * Atomically mutate the persisted state map under an exclusive flock(LOCK_EX).
     *
     * @template TReturn
     *
     * @param  Closure(array<string, WorktreeState>): array{0: array<string, WorktreeState>, 1: TReturn}  $callback
     * @return TReturn
     */
    public function mutate(Closure $callback): mixed
    {
        return $this->withLock(LOCK_EX, function () use ($callback): mixed {
            $states = $this->readUnlocked();
            [$updated, $result] = $callback($states);
            $this->writeUnlocked($updated);

            return $result;
        });
    }

    /**
     * @return array<int, int>
     */
    public function reservedVitePorts(): array
    {
        $ports = [];
        foreach ($this->all() as $state) {
            if ($state->vite_port !== null) {
                $ports[] = $state->vite_port;
            }
        }

        return $ports;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withLock(int $lockMode, Closure $callback): mixed
    {
        $this->paths->ensureDirectories();
        $lockFile = $this->paths->stateLockFile();

        $handle = @fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Unable to open state lock file: {$lockFile}");
        }

        try {
            if (! flock($handle, $lockMode)) {
                throw new RuntimeException("Unable to acquire lock on state file: {$lockFile}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, WorktreeState>
     */
    private function readUnlocked(): array
    {
        $stateFile = $this->paths->stateFile();
        if (! is_file($stateFile)) {
            return [];
        }

        $raw = @file_get_contents($stateFile);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = $decoded['worktrees'] ?? $decoded;
        if (! is_array($items)) {
            return [];
        }

        $states = [];
        foreach ($items as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $state = WorktreeState::fromArray($row);
            $slug = $state->slug !== '' ? $state->slug : (string) $key;
            if ($slug !== '') {
                $states[$slug] = $state;
            }
        }

        return $states;
    }

    /**
     * @param  array<string, WorktreeState>  $states
     */
    private function writeUnlocked(array $states): void
    {
        $stateFile = $this->paths->stateFile();
        $payload = [
            'version' => 1,
            'updated_at' => gmdate('c'),
            'worktrees' => array_map(
                static fn (WorktreeState $state): array => $state->toArray(),
                $states
            ),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if ($json === false) {
            throw new RuntimeException('Failed to encode state JSON.');
        }

        $tmpFile = $stateFile.'.tmp.'.getmypid();
        if (file_put_contents($tmpFile, $json) === false) {
            throw new RuntimeException("Failed to write temporary state file: {$tmpFile}");
        }

        if (! @rename($tmpFile, $stateFile)) {
            @unlink($tmpFile);
            throw new RuntimeException("Failed to atomically replace state file: {$stateFile}");
        }
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }
}
