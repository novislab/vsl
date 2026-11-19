<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CaddyClient
{
    public const DEFAULT_ADMIN_URL = 'http://127.0.0.1:2019';

    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $envUrl = $_ENV['VSL_CADDY_ADMIN'] ?? $_SERVER['VSL_CADDY_ADMIN'] ?? getenv('VSL_CADDY_ADMIN');
        $resolved = $baseUrl
            ?? (is_string($envUrl) && $envUrl !== '' ? $envUrl : self::DEFAULT_ADMIN_URL);

        $this->baseUrl = rtrim($resolved, '/');
    }

    /**
     * Ensure HTTP server srv0 exists on Caddy and listens on :80 and :443.
     */
    public function ensureServerExists(string $server = 'srv0'): void
    {
        $check = Http::timeout(2)->get("{$this->baseUrl}/config/apps/http/servers/{$server}");
        $this->applyServerCheck($check, $server);
    }

    /**
     * Delete a previously registered vsl-<slug> route, ignoring 404.
     */
    public function deleteRoute(string $slug): void
    {
        $response = Http::timeout(2)->delete("{$this->baseUrl}/id/vsl-{$slug}");

        if ($response->status() === 404 || $response->successful()) {
            return;
        }

        throw new RuntimeException(
            "Failed to delete Caddy route vsl-{$slug} (HTTP {$response->status()}): {$response->body()}"
        );
    }

    /**
     * Register a reverse proxy route for <domain> -> 127.0.0.1:<port> under @id vsl-<slug>,
     * optionally routing Vite dev assets and HMR WebSockets to 127.0.0.1:<vitePort>.
     *
     * @param  array<int, string>  $vitePaths
     */
    public function registerRoute(
        string $slug,
        string $domain,
        int $port,
        string $server = 'srv0',
        ?int $vitePort = null,
        array $vitePaths = [],
        ?string $hmrPath = null,
    ): void {
        // Overlap server existence check and stale route deletion in one parallel round-trip
        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('check')->timeout(2)->get("{$this->baseUrl}/config/apps/http/servers/{$server}"),
            $pool->as('delete')->timeout(2)->delete("{$this->baseUrl}/id/vsl-{$slug}"),
        ]);

        $this->applyServerCheck($responses['check'], $server);

        $delete = $responses['delete'];
        if ($delete->status() !== 404 && ! $delete->successful()) {
            throw new RuntimeException(
                "Failed to delete Caddy route vsl-{$slug} (HTTP {$delete->status()}): {$delete->body()}"
            );
        }

        $subroutes = [];

        if ($vitePort !== null) {
            $paths = array_values(array_unique(array_merge([
                '/@vite/*',
                '/@fs/*',
                '/@id/*',
                '/@react-refresh',
                '/__vite_ping',
                '/resources/*',
                '/node_modules/*',
            ], $vitePaths)));

            if ($hmrPath !== null && $hmrPath !== '' && $hmrPath !== '/') {
                $paths[] = $hmrPath;
            }

            $subroutes[] = [
                'match' => [
                    ['path' => $paths],
                ],
                'handle' => [
                    [
                        'handler' => 'reverse_proxy',
                        'upstreams' => [
                            ['dial' => "127.0.0.1:{$vitePort}"],
                        ],
                    ],
                ],
            ];

            $subroutes[] = [
                'match' => [
                    [
                        'header' => [
                            'Sec-WebSocket-Protocol' => ['vite-hmr'],
                        ],
                    ],
                ],
                'handle' => [
                    [
                        'handler' => 'reverse_proxy',
                        'upstreams' => [
                            ['dial' => "127.0.0.1:{$vitePort}"],
                        ],
                    ],
                ],
            ];
        }

        $subroutes[] = [
            'handle' => [
                [
                    'handler' => 'reverse_proxy',
                    'upstreams' => [
                        ['dial' => "127.0.0.1:{$port}"],
                    ],
                ],
            ],
        ];

        $payload = [
            '@id' => "vsl-{$slug}",
            'match' => [
                ['host' => [$domain]],
            ],
            'handle' => [
                [
                    'handler' => 'subroute',
                    'routes' => $subroutes,
                ],
            ],
            'terminal' => true,
        ];

        $response = Http::timeout(2)
            ->asJson()
            ->post("{$this->baseUrl}/config/apps/http/servers/{$server}/routes", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to register Caddy route vsl-{$slug} (HTTP {$response->status()}): {$response->body()}"
            );
        }
    }

    private function applyServerCheck(Response $check, string $server): void
    {
        if ($check->successful() && is_array($check->json())) {
            $listen = $check->json('listen');
            if (is_array($listen) && in_array(':443', $listen, true)) {
                return;
            }

            $mergedListen = array_values(array_unique(array_merge(
                is_array($listen) ? $listen : [],
                [':80', ':443']
            )));
            Http::timeout(2)->asJson()->put("{$this->baseUrl}/config/apps/http/servers/{$server}/listen", $mergedListen);

            return;
        }

        $isMissing = $check->status() === 404
            || $check->successful()
            || ($check->status() === 400 && str_contains($check->body(), 'invalid traversal path'));

        if (! $isMissing) {
            throw new RuntimeException(
                "Caddy Admin API check failed (HTTP {$check->status()}): {$check->body()}"
            );
        }

        $serverConfig = [
            'listen' => [':80', ':443'],
            'routes' => [],
        ];

        $create = Http::timeout(2)
            ->asJson()
            ->put("{$this->baseUrl}/config/apps/http/servers/{$server}", $serverConfig);

        if ($create->successful()) {
            return;
        }

        $bootstrap = Http::timeout(2)
            ->asJson()
            ->put("{$this->baseUrl}/config/apps/http", [
                'servers' => [
                    $server => $serverConfig,
                ],
            ]);

        if (! $bootstrap->successful()) {
            throw new RuntimeException(
                "Failed to initialize Caddy server {$server} (HTTP {$bootstrap->status()}): {$bootstrap->body()}"
            );
        }
    }
}
