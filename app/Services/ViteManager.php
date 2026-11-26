<?php

namespace App\Services;

use App\Support\VslPaths;
use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ViteManager
{
    public const PORT_MIN = 5173;

    public const PORT_MAX = 5899;

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
     * Inspect package.json and vite.config.{ts,js,mts,mjs} to detect Vite setup and custom asset paths.
     *
     * @return array{
     *     configFile: string,
     *     isTypeScript: bool,
     *     customPaths: array<int, string>,
     *     hmrPath: string|null
     * }|null
     */
    public function detectConfig(string $worktreePath): ?array
    {
        $root = rtrim($worktreePath, '/');
        $packageJsonPath = $root.'/package.json';
        if (! is_file($packageJsonPath)) {
            return null;
        }

        $pkg = json_decode((string) @file_get_contents($packageJsonPath), true);
        if (! is_array($pkg)) {
            return null;
        }

        $hasDevScript = isset($pkg['scripts']['dev']) && is_string($pkg['scripts']['dev']);
        $hasViteDep = isset($pkg['devDependencies']['vite']) || isset($pkg['dependencies']['vite']);
        if (! $hasDevScript && ! $hasViteDep) {
            return null;
        }

        $configFile = null;
        $isTypeScript = false;
        foreach (['vite.config.ts', 'vite.config.mts', 'vite.config.js', 'vite.config.mjs'] as $candidate) {
            $full = $root.'/'.$candidate;
            if (is_file($full)) {
                $configFile = $full;
                $isTypeScript = str_ends_with($candidate, '.ts') || str_ends_with($candidate, '.mts');
                break;
            }
        }

        if ($configFile === null) {
            return null;
        }

        $contents = (string) @file_get_contents($configFile);
        [$customPaths, $hmrPath] = self::parseViteConfigHints($contents);

        return [
            'configFile' => $configFile,
            'isTypeScript' => $isTypeScript,
            'customPaths' => $customPaths,
            'hmrPath' => $hmrPath,
        ];
    }

    /**
     * Parse custom entry directories and hmr.path from user vite.config.* without executing Node.
     *
     * @return array{0: array<int, string>, 1: string|null}
     */
    public static function parseViteConfigHints(string $contents): array
    {
        $paths = [];

        // Extract quoted asset paths like 'resources/js/app.tsx', 'src/main.ts', 'assets/app.css'
        if (preg_match_all('/[\'"]([a-zA-Z0-9_-]+)\/[a-zA-Z0-9_.\/-]+\.(?:js|ts|jsx|tsx|mjs|css|scss|sass|less|vue|svelte)[\'"]/', $contents, $matches)) {
            foreach ($matches[1] as $topDir) {
                $routePattern = '/'.$topDir.'/*';
                if (! in_array($routePattern, $paths, true)) {
                    $paths[] = $routePattern;
                }
            }
        }

        // Extract optional custom base: '/custom-base/'
        if (preg_match('/\bbase\s*:\s*[\'"]([^\'"]+)[\'"]/', $contents, $baseMatch)) {
            $base = '/'.trim($baseMatch[1], '/').'/*';
            if ($base !== '//*' && ! in_array($base, $paths, true)) {
                $paths[] = $base;
            }
        }

        // Extract optional hmr.path
        $hmrPath = null;
        if (preg_match('/hmr\s*:\s*\{[^}]*\bpath\s*:\s*[\'"]([^\'"]+)[\'"]/s', $contents, $hmrMatch)) {
            $hmrPath = '/'.ltrim($hmrMatch[1], '/');
        }

        return [$paths, $hmrPath];
    }

    /**
     * Allocate an unused local TCP port for Vite in 5173–5899.
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
            sprintf('No unused Vite ports available in range %d-%d.', self::PORT_MIN, self::PORT_MAX)
        );
    }

    /**
     * Write an untracked wrapper Vite config in ~/.vsl/vite/ that wraps the user's vite.config.*
     * and injects Yerd .test origin + WSS/WS HMR settings without modifying the git working tree.
     */
    public function writeWrapperConfig(string $slug, string $userConfigPath, bool $isTypeScript): string
    {
        $this->paths->ensureDirectories();
        $ext = $isTypeScript ? 'ts' : 'mjs';
        $wrapperPath = $this->paths->viteDir().'/'.$slug.'.config.'.$ext;
        $escapedImport = json_encode('file://'.$userConfigPath, JSON_UNESCAPED_SLASHES);
        $defaultHmrPath = YerdClient::DEFAULT_HMR_PATH;

        $script = <<<JS
import userConfig from {$escapedImport};

export default async function vslViteConfig(env) {
  const resolved = typeof userConfig === 'function' ? await userConfig(env) : (userConfig ?? {});
  const domain = process.env.VSL_PREVIEW_DOMAIN;
  const isSecure = process.env.VSL_PREVIEW_SECURE !== '0';
  const origin = process.env.VSL_PREVIEW_URL || `\${isSecure ? 'https' : 'http'}://\${domain}`;
  const port = Number(process.env.VSL_VITE_PORT || 5173);
  const userServer = resolved.server ?? {};
  const userHmr = typeof userServer.hmr === 'object' && userServer.hmr !== null ? userServer.hmr : {};
  const hmrPath = userHmr.path && userHmr.path !== '/' ? userHmr.path : '{$defaultHmrPath}';

  return {
    ...resolved,
    server: {
      ...userServer,
      host: '127.0.0.1',
      port,
      strictPort: true,
      cors: true,
      origin,
      hmr: {
        ...userHmr,
        host: domain,
        path: hmrPath,
        protocol: isSecure ? 'wss' : 'ws',
        clientPort: isSecure ? 443 : 80,
      },
    },
  };
}
JS;

        if (file_put_contents($wrapperPath, $script."\n") === false) {
            throw new RuntimeException("Unable to write Vite wrapper config: {$wrapperPath}");
        }

        return $wrapperPath;
    }

    /**
     * Spawn `bun dev` (Vite HMR) in a detached background process group out of the terminal.
     *
     * @return array{
     *     port: int,
     *     pid: int,
     *     pgid: int,
     *     log: string,
     *     customPaths: array<int, string>,
     *     hmrPath: string
     * }|null
     */
    public function dispatch(
        string $worktreePath,
        string $slug,
        string $domain,
        int $vitePort,
        bool $secure = true,
    ): ?array {
        $detected = $this->detectConfig($worktreePath);
        if ($detected === null) {
            return null;
        }

        $runner = $this->resolveDevRunner($worktreePath);
        if ($runner === null) {
            return null;
        }

        $this->paths->ensureDirectories();
        $logFile = $this->paths->viteLogFile($slug);
        $wrapperPath = $this->writeWrapperConfig($slug, $detected['configFile'], $detected['isTypeScript']);
        $previewUrl = ($secure ? 'https' : 'http')."://{$domain}";

        $viteArgs = sprintf(
            '--config %s --host 127.0.0.1 --port %d',
            escapeshellarg($wrapperPath),
            $vitePort
        );

        $shellCmd = sprintf(
            'setsid env VSL_PREVIEW_DOMAIN=%s VSL_PREVIEW_URL=%s VSL_PREVIEW_SECURE=%s VSL_VITE_PORT=%d %s %s > %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($domain),
            escapeshellarg($previewUrl),
            $secure ? '1' : '0',
            $vitePort,
            $runner,
            $viteArgs,
            escapeshellarg($logFile)
        );

        $result = Process::path($worktreePath)->run(['bash', '-c', $shellCmd]);
        if (! $result->successful()) {
            return null;
        }

        $pid = (int) trim($result->output());
        if ($pid <= 0) {
            return null;
        }

        $pgid = ProcessManager::resolveChildPgid($pid);
        $hmrPath = ($detected['hmrPath'] !== null && $detected['hmrPath'] !== '' && $detected['hmrPath'] !== '/')
            ? $detected['hmrPath']
            : YerdClient::DEFAULT_HMR_PATH;

        return [
            'port' => $vitePort,
            'pid' => $pid,
            'pgid' => $pgid,
            'log' => $logFile,
            'customPaths' => $detected['customPaths'],
            'hmrPath' => $hmrPath,
        ];
    }

    /**
     * Stop the detached Vite process group and remove public/hot + wrapper/log artifacts.
     */
    public function cleanup(string $worktreePath, string $slug, ?int $pid, ?int $pgid, ProcessManager $processManager): void
    {
        if ($pid !== null && $pid > 0) {
            $processManager->stop($pid, $pgid ?: $pid);
        }

        $hotFile = rtrim($worktreePath, '/').'/public/hot';
        if (is_file($hotFile)) {
            @unlink($hotFile);
        }

        $logFile = $this->paths->viteLogFile($slug);
        if (is_file($logFile)) {
            @unlink($logFile);
        }

        foreach (['mjs', 'ts'] as $ext) {
            $wrapper = $this->paths->viteDir().'/'.$slug.'.config.'.$ext;
            if (is_file($wrapper)) {
                @unlink($wrapper);
            }
        }
    }

    public function findBunBinary(): ?string
    {
        $home = $_ENV['HOME'] ?? $_SERVER['HOME'] ?? getenv('HOME');
        $candidates = [];

        if (is_string($home) && $home !== '') {
            $home = rtrim($home, '/');
            $candidates[] = $home.'/.local/share/yerd/bin/bun';
            $candidates[] = $home.'/Library/Application Support/yerd/bin/bun';
            $candidates[] = $home.'/.bun/bin/bun';
        }
        $candidates[] = '/usr/local/bin/bun';
        $candidates[] = '/usr/bin/bun';

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $which = Process::run(['which', 'bun']);
        if ($which->successful() && trim($which->output()) !== '') {
            return trim($which->output());
        }

        return null;
    }

    private function resolveDevRunner(string $worktreePath): ?string
    {
        $bun = $this->findBunBinary();
        $localVite = rtrim($worktreePath, '/').'/node_modules/.bin/vite';

        if ($bun !== null) {
            if (is_file($localVite)) {
                return escapeshellarg($bun).' --bun '.escapeshellarg($localVite);
            }

            return escapeshellarg($bun).' run --silent dev --';
        }

        if (is_file($localVite)) {
            return escapeshellarg($localVite);
        }

        return null;
    }
}
