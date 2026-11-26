<?php

namespace App\Data;

readonly class WorktreeState
{
    /**
     * @param  array<int, string>  $vite_proxy_paths
     */
    public function __construct(
        public string $slug,
        public string $branch,
        public string $domain,
        public string $url,
        public string $path,
        public string $parent_path,
        public string $created_at,
        public bool $secure = true,
        public ?string $php_version = null,
        public ?string $db_connection = null,
        public ?string $db_name = null,
        public ?int $db_pid = null,
        public ?int $db_pgid = null,
        public ?int $vite_port = null,
        public ?int $vite_pid = null,
        public ?int $vite_pgid = null,
        public array $vite_proxy_paths = [],
    ) {}

    /**
     * Hydrate a WorktreeState DTO from a decoded JSON array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawProxyPaths = $data['vite_proxy_paths'] ?? [];
        $proxyPaths = is_array($rawProxyPaths)
            ? array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $rawProxyPaths), static fn (string $v): bool => $v !== ''))
            : [];

        return new self(
            slug: (string) ($data['slug'] ?? ''),
            branch: (string) ($data['branch'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            path: (string) ($data['path'] ?? ''),
            parent_path: (string) ($data['parent_path'] ?? ''),
            created_at: (string) ($data['created_at'] ?? ''),
            secure: array_key_exists('secure', $data) ? (bool) $data['secure'] : true,
            php_version: isset($data['php_version']) && $data['php_version'] !== '' ? (string) $data['php_version'] : null,
            db_connection: isset($data['db_connection']) && $data['db_connection'] !== '' ? (string) $data['db_connection'] : null,
            db_name: isset($data['db_name']) && $data['db_name'] !== '' ? (string) $data['db_name'] : null,
            db_pid: isset($data['db_pid']) ? (int) $data['db_pid'] : null,
            db_pgid: isset($data['db_pgid']) ? (int) $data['db_pgid'] : null,
            vite_port: isset($data['vite_port']) ? (int) $data['vite_port'] : null,
            vite_pid: isset($data['vite_pid']) ? (int) $data['vite_pid'] : null,
            vite_pgid: isset($data['vite_pgid']) ? (int) $data['vite_pgid'] : null,
            vite_proxy_paths: $proxyPaths,
        );
    }

    /**
     * Convert this DTO to a JSON-serializable associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'branch' => $this->branch,
            'domain' => $this->domain,
            'url' => $this->url,
            'path' => $this->path,
            'parent_path' => $this->parent_path,
            'created_at' => $this->created_at,
            'secure' => $this->secure,
            'php_version' => $this->php_version,
            'db_connection' => $this->db_connection,
            'db_name' => $this->db_name,
            'db_pid' => $this->db_pid,
            'db_pgid' => $this->db_pgid,
            'vite_port' => $this->vite_port,
            'vite_pid' => $this->vite_pid,
            'vite_pgid' => $this->vite_pgid,
            'vite_proxy_paths' => $this->vite_proxy_paths,
        ];
    }

    /**
     * Return a copy updated with a new Vite dev server port, PID, PGID, and proxy paths.
     *
     * @param  array<int, string>|null  $viteProxyPaths
     */
    public function withVite(?int $vitePort, ?int $vitePid, ?int $vitePgid, ?array $viteProxyPaths = null): self
    {
        return new self(
            slug: $this->slug,
            branch: $this->branch,
            domain: $this->domain,
            url: $this->url,
            path: $this->path,
            parent_path: $this->parent_path,
            created_at: $this->created_at,
            secure: $this->secure,
            php_version: $this->php_version,
            db_connection: $this->db_connection,
            db_name: $this->db_name,
            db_pid: $this->db_pid,
            db_pgid: $this->db_pgid,
            vite_port: $vitePort,
            vite_pid: $vitePid,
            vite_pgid: $vitePgid,
            vite_proxy_paths: $viteProxyPaths ?? $this->vite_proxy_paths,
        );
    }
}
