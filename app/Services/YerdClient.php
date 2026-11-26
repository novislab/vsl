<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class YerdClient
{
    public const DEFAULT_TLD = 'test';

    public const DEFAULT_HMR_PATH = '/__vite_hmr';

    private ?string $binary = null;

    private ?string $cachedTld = null;

    public function __construct(?string $binary = null)
    {
        if ($binary !== null && $binary !== '') {
            $this->binary = $binary;
        }
    }

    /**
     * Resolve the yerd CLI binary path (works in non-interactive shells, hooks, and systemd).
     */
    public function findBinary(): string
    {
        if ($this->binary !== null) {
            return $this->binary;
        }

        $envBin = $_ENV['VSL_YERD_BIN'] ?? $_SERVER['VSL_YERD_BIN'] ?? getenv('VSL_YERD_BIN');
        if (is_string($envBin) && trim($envBin) !== '') {
            return $this->binary = trim($envBin);
        }

        $home = $_ENV['HOME'] ?? $_SERVER['HOME'] ?? getenv('HOME');
        $candidates = [];

        if (is_string($home) && $home !== '') {
            $home = rtrim($home, '/');
            $candidates[] = $home.'/.local/share/yerd/bin/yerd';
            $candidates[] = $home.'/Library/Application Support/yerd/bin/yerd';
        }

        $candidates[] = '/usr/local/bin/yerd';
        $candidates[] = '/usr/bin/yerd';
        $candidates[] = '/opt/homebrew/bin/yerd';

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $this->binary = $path;
            }
        }

        $which = Process::run(['which', 'yerd']);
        if ($which->successful() && trim($which->output()) !== '') {
            return $this->binary = trim($which->output());
        }

        return $this->binary = 'yerd';
    }

    /**
     * Resolve Yerd's configured local TLD (e.g. "test") via VSL_YERD_TLD or `yerd status --json`.
     */
    public function resolveTld(): string
    {
        $envTld = $_ENV['VSL_YERD_TLD'] ?? $_SERVER['VSL_YERD_TLD'] ?? getenv('VSL_YERD_TLD');
        if (is_string($envTld) && trim($envTld, ". \t\n\r") !== '') {
            return ltrim(trim($envTld), '.');
        }

        if ($this->cachedTld !== null) {
            return $this->cachedTld;
        }

        $result = Process::run([$this->findBinary(), 'status', '--json']);
        if ($result->successful()) {
            $decoded = json_decode($result->output(), true);
            $tld = $decoded['report']['tld'] ?? null;
            if (is_string($tld) && trim($tld, ". \t\n\r") !== '') {
                return $this->cachedTld = ltrim(trim($tld), '.');
            }
        }

        return $this->cachedTld = self::DEFAULT_TLD;
    }

    /**
     * Build the local Yerd domain and URL for a worktree slug.
     *
     * @return array{tld: string, domain: string, url: string}
     */
    public function buildPreviewUrl(string $slug, bool $secure = true, ?string $tld = null): array
    {
        $resolvedTld = $tld !== null && trim($tld, ". \t\n\r") !== ''
            ? ltrim(trim($tld), '.')
            : $this->resolveTld();

        $domain = "{$slug}.{$resolvedTld}";
        $scheme = $secure ? 'https' : 'http';

        return [
            'tld' => $resolvedTld,
            'domain' => $domain,
            'url' => "{$scheme}://{$domain}",
        ];
    }

    /**
     * List all parked and linked sites reported by `yerd sites --json`, keyed by site name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function listSites(): array
    {
        $result = Process::run([$this->findBinary(), 'sites', '--json']);
        if (! $result->successful()) {
            return [];
        }

        $decoded = json_decode($result->output(), true);
        $sites = $decoded['sites'] ?? null;
        if (! is_array($sites)) {
            return [];
        }

        $indexed = [];
        foreach ($sites as $site) {
            if (is_array($site) && isset($site['name']) && is_string($site['name']) && $site['name'] !== '') {
                $indexed[$site['name']] = $site;
            }
        }

        return $indexed;
    }

    /**
     * Detect the PHP version pinned to the parent repository in Yerd (if any).
     */
    public function detectParentPhpVersion(string $parentPath): ?string
    {
        $normalizedParent = $this->normalizePath($parentPath);

        foreach ($this->listSites() as $site) {
            $docRoot = $site['document_root'] ?? null;
            if (! is_string($docRoot) || $docRoot === '') {
                continue;
            }

            if ($this->normalizePath($docRoot) === $normalizedParent) {
                $php = $site['php'] ?? null;

                return is_string($php) && trim($php) !== '' ? trim($php) : null;
            }
        }

        return null;
    }

    /**
     * Link a worktree directory in Yerd, optionally pin its PHP version, enable HTTPS,
     * and attach path-based reverse proxy rules for Vite dev assets + WSS HMR.
     *
     * @param  array<int, string>  $vitePaths
     * @return array<int, string> Registered Vite proxy path prefixes
     */
    public function registerSite(
        string $slug,
        string $worktreePath,
        bool $secure = true,
        ?string $phpVersion = null,
        ?int $vitePort = null,
        array $vitePaths = [],
        ?string $hmrPath = null,
    ): array {
        $bin = $this->findBinary();

        $this->runOrFail([$bin, 'link', $slug, $worktreePath], "Failed to link Yerd site '{$slug}'");

        if ($phpVersion !== null && trim($phpVersion) !== '') {
            $this->runOrFail([$bin, 'use', $slug, trim($phpVersion)], "Failed to set PHP {$phpVersion} for Yerd site '{$slug}'");
        }

        if ($secure) {
            $this->runOrFail([$bin, 'secure', $slug], "Failed to secure Yerd site '{$slug}'");
        } else {
            Process::run([$bin, 'unsecure', $slug]);
        }

        if ($vitePort === null) {
            return [];
        }

        $prefixes = self::buildViteProxyPrefixes($vitePaths, $hmrPath);
        $upstream = "http://127.0.0.1:{$vitePort}";

        foreach ($prefixes as $prefix) {
            $this->runOrFail(
                [$bin, 'proxy', 'add', $slug, $prefix, $upstream],
                "Failed to register Yerd Vite proxy '{$prefix}' for '{$slug}'"
            );
        }

        return $prefixes;
    }

    /**
     * Remove any registered Vite proxy rules and unlink the site from Yerd (idempotent).
     *
     * @param  array<int, string>  $viteProxyPaths
     */
    public function unregisterSite(string $slug, array $viteProxyPaths = []): void
    {
        $bin = $this->findBinary();

        foreach ($viteProxyPaths as $prefix) {
            if ($prefix === '') {
                continue;
            }

            Process::run([$bin, 'proxy', 'remove', $slug, $prefix]);
        }

        $result = Process::run([$bin, 'unlink', $slug]);
        if ($result->successful()) {
            return;
        }

        $combined = strtolower(trim($result->errorOutput()."\n".$result->output()));
        if (
            str_contains($combined, 'not found')
            || str_contains($combined, 'not linked')
            || str_contains($combined, 'no site')
            || str_contains($combined, 'does not exist')
        ) {
            return;
        }

        $error = trim($result->errorOutput()) ?: trim($result->output());
        throw new RuntimeException("Failed to unlink Yerd site '{$slug}': {$error}");
    }

    /**
     * Build deduplicated Yerd path proxy prefixes for Vite dev assets and HMR WebSocket.
     *
     * @param  array<int, string>  $vitePaths
     * @return array<int, string>
     */
    public static function buildViteProxyPrefixes(array $vitePaths = [], ?string $hmrPath = null): array
    {
        $raw = array_merge([
            '/@vite',
            '/@fs',
            '/@id',
            '/@react-refresh',
            '/__vite_ping',
            '/resources',
            '/node_modules',
        ], $vitePaths);

        $resolvedHmr = ($hmrPath !== null && trim($hmrPath) !== '' && trim($hmrPath) !== '/')
            ? $hmrPath
            : self::DEFAULT_HMR_PATH;
        $raw[] = $resolvedHmr;

        $prefixes = [];
        foreach ($raw as $candidate) {
            $normalized = self::normalizeProxyPrefix($candidate);
            if ($normalized !== null && ! in_array($normalized, $prefixes, true)) {
                $prefixes[] = $normalized;
            }
        }

        return $prefixes;
    }

    /**
     * Convert a route pattern like '/resources/*' into a clean Yerd path prefix '/resources'.
     */
    public static function normalizeProxyPrefix(string $pattern): ?string
    {
        $trimmed = trim($pattern);
        if ($trimmed === '') {
            return null;
        }

        $trimmed = rtrim($trimmed, '*');
        $trimmed = rtrim($trimmed, '/');

        if ($trimmed === '') {
            return null;
        }

        return '/'.ltrim($trimmed, '/');
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runOrFail(array $command, string $context): void
    {
        $result = Process::run($command);
        if ($result->successful()) {
            return;
        }

        $error = trim($result->errorOutput()) ?: trim($result->output());
        throw new RuntimeException("{$context}: {$error}");
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }
}
