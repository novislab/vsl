<?php

namespace App\Data;

readonly class WorktreeState
{
    public function __construct(
        public string $slug,
        public string $branch,
        public string $domain,
        public string $url,
        public int $port,
        public int $pid,
        public int $pgid,
        public string $path,
        public string $parent_path,
        public string $created_at,
        public ?string $db_connection = null,
        public ?string $db_name = null,
        public ?int $db_pid = null,
        public ?int $db_pgid = null,
        public ?int $vite_port = null,
        public ?int $vite_pid = null,
        public ?int $vite_pgid = null,
    ) {}

    /**
     * Hydrate a WorktreeState DTO from a decoded JSON array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) ($data['slug'] ?? ''),
            branch: (string) ($data['branch'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            port: (int) ($data['port'] ?? 0),
            pid: (int) ($data['pid'] ?? 0),
            pgid: (int) ($data['pgid'] ?? 0),
            path: (string) ($data['path'] ?? ''),
            parent_path: (string) ($data['parent_path'] ?? ''),
            created_at: (string) ($data['created_at'] ?? ''),
            db_connection: isset($data['db_connection']) && $data['db_connection'] !== '' ? (string) $data['db_connection'] : null,
            db_name: isset($data['db_name']) && $data['db_name'] !== '' ? (string) $data['db_name'] : null,
            db_pid: isset($data['db_pid']) ? (int) $data['db_pid'] : null,
            db_pgid: isset($data['db_pgid']) ? (int) $data['db_pgid'] : null,
            vite_port: isset($data['vite_port']) ? (int) $data['vite_port'] : null,
            vite_pid: isset($data['vite_pid']) ? (int) $data['vite_pid'] : null,
            vite_pgid: isset($data['vite_pgid']) ? (int) $data['vite_pgid'] : null,
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
            'port' => $this->port,
            'pid' => $this->pid,
            'pgid' => $this->pgid,
            'path' => $this->path,
            'parent_path' => $this->parent_path,
            'created_at' => $this->created_at,
            'db_connection' => $this->db_connection,
            'db_name' => $this->db_name,
            'db_pid' => $this->db_pid,
            'db_pgid' => $this->db_pgid,
            'vite_port' => $this->vite_port,
            'vite_pid' => $this->vite_pid,
            'vite_pgid' => $this->vite_pgid,
        ];
    }

    /**
     * Return a copy updated with a new Octane worker port, PID, and PGID.
     */
    public function withWorker(int $port, int $pid, int $pgid): self
    {
        return new self(
            slug: $this->slug,
            branch: $this->branch,
            domain: $this->domain,
            url: $this->url,
            port: $port,
            pid: $pid,
            pgid: $pgid,
            path: $this->path,
            parent_path: $this->parent_path,
            created_at: $this->created_at,
            db_connection: $this->db_connection,
            db_name: $this->db_name,
            db_pid: $this->db_pid,
            db_pgid: $this->db_pgid,
            vite_port: $this->vite_port,
            vite_pid: $this->vite_pid,
            vite_pgid: $this->vite_pgid,
        );
    }

    /**
     * Return a copy updated with a new Vite dev server port, PID, and PGID.
     */
    public function withVite(?int $vitePort, ?int $vitePid, ?int $vitePgid): self
    {
        return new self(
            slug: $this->slug,
            branch: $this->branch,
            domain: $this->domain,
            url: $this->url,
            port: $this->port,
            pid: $this->pid,
            pgid: $this->pgid,
            path: $this->path,
            parent_path: $this->parent_path,
            created_at: $this->created_at,
            db_connection: $this->db_connection,
            db_name: $this->db_name,
            db_pid: $this->db_pid,
            db_pgid: $this->db_pgid,
            vite_port: $vitePort,
            vite_pid: $vitePid,
            vite_pgid: $vitePgid,
        );
    }
}
