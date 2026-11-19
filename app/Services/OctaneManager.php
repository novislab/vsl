<?php

namespace App\Services;

use App\Support\VslPaths;
use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class OctaneManager
{
    public const PORT_MIN = 9100;

    public const PORT_MAX = 9900;

    /** @var Closure(int): bool */
    private readonly Closure $portChecker;

    /**
     * @param  (Closure(int): bool)|null  $portChecker
     */
    public function __construct(
        private readonly VslPaths $paths = new VslPaths,
        ?Closure $portChecker = null,
    ) {
        $this->portChecker = $portChecker ?? static function (int $port): bool {
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($socket === false) {
                return false;
            }
            fclose($socket);

            return true;
        };
    }

    /**
     * Allocate an unused TCP port in 9100–9900, skipping reserved ports.
     *
     * @param  array<int, int>  $reservedPorts
     */
    public function allocatePort(array $reservedPorts = [], ?int $preferredPort = null): int
    {
        $reservedMap = array_flip($reservedPorts);

        if (
            $preferredPort !== null
            && $preferredPort >= self::PORT_MIN
            && $preferredPort <= self::PORT_MAX
            && ! isset($reservedMap[$preferredPort])
            && ($this->portChecker)($preferredPort)
        ) {
            return $preferredPort;
        }

        for ($port = self::PORT_MIN; $port <= self::PORT_MAX; $port++) {
            if (isset($reservedMap[$port])) {
                continue;
            }

            if (($this->portChecker)($port)) {
                return $port;
            }
        }

        throw new RuntimeException(
            sprintf('No unused TCP ports available in range %d-%d.', self::PORT_MIN, self::PORT_MAX)
        );
    }

    /**
     * Spawn a detached Laravel app server (Octane if installed, otherwise FrankenPHP php-server
     * or `php artisan serve`) without waiting for TCP readiness yet.
     *
     * @return array{port: int, pid: int, pgid: int, log: string, reload_strategy: string}
     */
    public function spawnDetached(string $worktreePath, string $slug, int $port, bool $devMode = true): array
    {
        $this->paths->ensureDirectories();
        $logFile = $this->paths->logFile($slug);

        [$serverCmd, $strategy] = self::buildServerCommand(
            worktreePath: $worktreePath,
            port: $port,
            devMode: $devMode,
            frankenPhpBin: $this->findFrankenPhpBinary(),
        );

        $command = sprintf(
            'setsid %s > %s 2>&1 < /dev/null & echo $!',
            $serverCmd,
            escapeshellarg($logFile)
        );

        $result = Process::path($worktreePath)->run(['bash', '-c', $command]);

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("Failed to start app server: {$error}");
        }

        $pid = (int) trim($result->output());
        if ($pid <= 0) {
            throw new RuntimeException(
                sprintf('App server launch did not return a valid PID. Output: %s', trim($result->output()))
            );
        }

        $pgid = self::resolveChildPgid($pid);

        return [
            'port' => $port,
            'pid' => $pid,
            'pgid' => $pgid,
            'log' => $logFile,
            'reload_strategy' => $strategy,
        ];
    }

    /**
     * Launch a detached Laravel app server and wait until it accepts TCP connections.
     *
     * @return array{port: int, pid: int, pgid: int}
     */
    public function start(string $worktreePath, string $slug, int $port, bool $devMode = true): array
    {
        $spawned = $this->spawnDetached($worktreePath, $slug, $port, $devMode);
        $this->waitUntilListening($spawned['port'], $spawned['pid'], $spawned['pgid'], $spawned['log']);

        return [
            'port' => $spawned['port'],
            'pid' => $spawned['pid'],
            'pgid' => $spawned['pgid'],
        ];
    }

    /**
     * Build the app server command:
     *  1. `php artisan octane:start` when laravel/octane (or custom octane:start) is present
     *  2. `CADDY_ADMIN=off frankenphp php-server` when standalone FrankenPHP is installed
     *  3. `php artisan serve` fallback
     *
     * @return array{0: string, 1: string}
     */
    public static function buildServerCommand(
        string $worktreePath,
        int $port,
        bool $devMode = true,
        ?string $frankenPhpBin = null,
    ): array {
        $root = rtrim($worktreePath, '/');
        $consolePath = $root.'/routes/console.php';
        $hasOctane = is_dir($root.'/vendor/laravel/octane')
            || (is_file($consolePath) && str_contains((string) file_get_contents($consolePath), 'octane:start'));

        if ($hasOctane) {
            [$flags, $strategy] = self::buildOctaneFlags($root, $port, $devMode);

            return ['php artisan octane:start '.$flags, $strategy];
        }

        if ($frankenPhpBin !== null && $frankenPhpBin !== '') {
            $cmd = sprintf(
                'env CADDY_ADMIN=off %s php-server --listen 127.0.0.1:%d --root %s',
                escapeshellarg($frankenPhpBin),
                $port,
                escapeshellarg($root.'/public')
            );

            return [$cmd, 'frankenphp-dev'];
        }

        return [
            sprintf('php artisan serve --host=127.0.0.1 --port=%d --no-reload', $port),
            'artisan-serve',
        ];
    }

    /**
     * Determine Octane CLI flags for instant dev reloading vs production mode.
     *
     * @return array{0: string, 1: string}
     */
    public static function buildOctaneFlags(string $worktreePath, int $port, bool $devMode = true): array
    {
        $hasChokidar = is_dir(rtrim($worktreePath, '/').'/node_modules/chokidar');

        if ($devMode && $hasChokidar) {
            return [
                sprintf('--server=frankenphp --host=127.0.0.1 --port=%d --workers=2 --max-requests=500 --watch', $port),
                'watch',
            ];
        }

        if ($devMode) {
            return [
                sprintf('--server=frankenphp --host=127.0.0.1 --port=%d --workers=2 --max-requests=1', $port),
                'stateless-dev',
            ];
        }

        return [
            sprintf('--server=frankenphp --host=127.0.0.1 --port=%d --workers=2 --max-requests=500', $port),
            'prod',
        ];
    }

    public function findFrankenPhpBinary(): ?string
    {
        foreach (['/usr/local/bin/frankenphp', '/usr/bin/frankenphp'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = Process::run(['which', 'frankenphp']);
        if ($which->successful() && trim($which->output()) !== '') {
            return trim($which->output());
        }

        return null;
    }

    /**
     * Resolve a safe child PGID that is never equal to the caller's own process group.
     */
    public static function resolveChildPgid(int $pid): int
    {
        if ($pid <= 0 || ! function_exists('posix_getpgid')) {
            return $pid;
        }

        $myPid = getmypid() ?: 0;
        $myPgid = $myPid > 0 ? (posix_getpgid($myPid) ?: 0) : 0;
        $childPgid = posix_getpgid($pid);

        if ($childPgid === false || $childPgid <= 0 || $childPgid === $myPgid) {
            return $pid;
        }

        return $childPgid;
    }

    /**
     * Terminate a detached worker process group (SIGTERM with 2s grace, then SIGKILL),
     * ensuring we never signal our own process or process group.
     */
    public function stop(int $pid, int $pgid): void
    {
        if (! function_exists('posix_kill')) {
            return;
        }

        $myPid = getmypid() ?: 0;
        $myPgid = ($myPid > 0 && function_exists('posix_getpgid')) ? (posix_getpgid($myPid) ?: 0) : 0;

        $useGroup = $pgid > 0 && $pgid !== $myPgid && $pgid !== $myPid;
        $target = $useGroup ? -$pgid : $pid;

        if ($target === 0 || $target === $myPid) {
            return;
        }

        if (! @posix_kill($target, 0) && ($pid <= 0 || $pid === $myPid || ! @posix_kill($pid, 0))) {
            return;
        }

        @posix_kill($target, SIGTERM);
        if ($pid > 0 && $pid !== $myPid) {
            @posix_kill($pid, SIGTERM);
        }

        $deadline = hrtime(true) + 2_000_000_000;
        while (hrtime(true) < $deadline) {
            $groupAlive = $useGroup && @posix_kill($target, 0);
            $pidAlive = $pid > 0 && $pid !== $myPid && @posix_kill($pid, 0);

            if (! $groupAlive && ! $pidAlive) {
                return;
            }

            usleep(10_000);
        }

        @posix_kill($target, SIGKILL);
        if ($pid > 0 && $pid !== $myPid) {
            @posix_kill($pid, SIGKILL);
        }
    }

    public function isAlive(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    /**
     * Poll 127.0.0.1:<port> every 10ms (up to 6s) until accepting TCP connections.
     */
    public function waitUntilListening(int $port, int $pid, int $pgid, string $logFile): void
    {
        $deadline = hrtime(true) + 6_000_000_000;

        while (hrtime(true) < $deadline) {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.05);
            if ($conn !== false) {
                fclose($conn);

                return;
            }

            if (! $this->isAlive($pid)) {
                $tail = $this->readLogTail($logFile);
                throw new RuntimeException(
                    "App server exited prematurely (PID {$pid}) on port {$port}".($tail !== '' ? ":\n{$tail}" : '.')
                );
            }

            usleep(10_000);
        }

        $this->stop($pid, $pgid);
        $tail = $this->readLogTail($logFile);

        throw new RuntimeException(
            "Timed out waiting for app server on 127.0.0.1:{$port} (PID {$pid})".($tail !== '' ? ":\n{$tail}" : '.')
        );
    }

    private function readLogTail(string $logFile, int $maxBytes = 2048): string
    {
        if (! is_file($logFile)) {
            return '';
        }

        $contents = @file_get_contents($logFile);
        if (! is_string($contents)) {
            return '';
        }

        $contents = trim($contents);
        if (strlen($contents) > $maxBytes) {
            return substr($contents, -$maxBytes);
        }

        return $contents;
    }
}
