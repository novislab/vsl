<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Data\WorktreeState;
use App\Services\CaddyClient;
use App\Services\DatabaseManager;
use App\Services\GitInspector;
use App\Services\IpResolver;
use App\Services\OctaneManager;
use App\Services\StateStore;
use App\Services\ViteManager;
use App\Services\WorkspaceHydrator;
use App\Support\HalfBlockQrOutput;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

class UpCommand extends Command
{
    use InteractsWithVslUi;

    protected $signature = 'up
        {--path= : Path to the Git worktree (defaults to current working directory)}
        {--no-qr : Suppress terminal QR code output}
        {--no-db : Skip isolated database creation, migration, and seeding}
        {--no-seed : Run migrations without seeding}
        {--no-vite : Skip starting bun dev / Vite HMR server}
        {--prod : Disable dev auto-reload flags (--watch / --max-requests=1)}
        {--json : Output result as JSON}';

    protected $description = 'Hydrate a Git worktree, start Octane + bun dev in parallel, and expose an HTTPS sslip.io preview URL';

    public function handle(
        GitInspector $gitInspector,
        IpResolver $ipResolver,
        WorkspaceHydrator $hydrator,
        DatabaseManager $databaseManager,
        ViteManager $viteManager,
        OctaneManager $octane,
        CaddyClient $caddy,
        StateStore $stateStore,
    ): int {
        $this->startTimer();
        $json = (bool) $this->option('json');
        $this->setupPromptsUi($json);

        $targetPath = (string) ($this->option('path') ?: getcwd());

        intro('VSL · Worktree Preview Up');

        $spawnedOctane = null;
        $viteJob = null;

        try {
            $info = $gitInspector->inspect($targetPath);
            $preview = $ipResolver->buildPreviewUrl($info->slug);
            $domain = $preview['domain'];
            $url = $preview['url'];
            $devMode = ! $this->option('prod');

            $allStates = $stateStore->all();

            // Idempotency: tear down any existing worker/vite/db/route for this slug first
            if (isset($allStates[$info->slug])) {
                $existing = $allStates[$info->slug];
                $this->spinStep("Replacing existing preview for {$info->slug}...", function () use ($existing, $octane, $viteManager, $caddy, $stateStore): void {
                    if ($existing->db_pid !== null && $existing->db_pid > 0) {
                        $octane->stop($existing->db_pid, $existing->db_pgid ?: $existing->db_pid);
                    }
                    $viteManager->cleanup($existing->path, $existing->slug, $existing->vite_pid, $existing->vite_pgid, $octane);
                    $octane->stop($existing->pid, $existing->pgid);
                    $caddy->deleteRoute($existing->slug);
                    $stateStore->remove($existing->slug);
                }, $json);

                unset($allStates[$info->slug]);
            }

            $dbConfig = null;
            $this->spinStep('Hydrating workspace (vendor, node_modules, storage, .env)...', function () use ($hydrator, $databaseManager, $info, $url, &$dbConfig): void {
                $hydrator->hydrate($info->parentPath, $info->worktreePath, $url);

                if (! $this->option('no-db')) {
                    $dbConfig = $databaseManager->prepareEnv($info->worktreePath, $info->slug);
                }
            }, $json);

            $reservedOctanePorts = array_values(array_map(static fn (WorktreeState $s): int => $s->port, $allStates));
            $reservedVitePorts = [];
            foreach ($allStates as $s) {
                if ($s->vite_port !== null) {
                    $reservedVitePorts[] = $s->vite_port;
                }
            }

            $octanePort = $octane->allocatePort($reservedOctanePorts);

            // Launch Octane, Vite (bun dev), DB provision, and Caddy registration concurrently!
            $spawnedOctane = $this->spinStep("Starting Octane + Vite HMR & registering {$domain}...", function () use (
                $octane,
                $viteManager,
                $databaseManager,
                $caddy,
                $info,
                $domain,
                $octanePort,
                $reservedVitePorts,
                $devMode,
                $dbConfig,
                &$viteJob
            ): array {
                // 1. Spawn Octane asynchronously (don't wait on TCP socket yet)
                $spawned = $octane->spawnDetached($info->worktreePath, $info->slug, $octanePort, $devMode);

                // 2. Spawn bun dev (Vite HMR) out of terminal if configured
                if (! $this->option('no-vite') && $viteManager->detectConfig($info->worktreePath) !== null) {
                    $vitePort = $viteManager->allocatePort($reservedVitePorts);
                    $viteJob = $viteManager->dispatch($info->worktreePath, $info->slug, $domain, $vitePort);
                }

                // 3. Spawn background DB create + migrate + seed out of terminal
                $dbJob = null;
                if ($dbConfig !== null) {
                    $dbJob = $databaseManager->dispatchProvision(
                        worktreePath: $info->worktreePath,
                        slug: $info->slug,
                        seed: ! $this->option('no-seed'),
                    );
                }

                // 4. Register Caddy HTTPS + Vite HMR proxy routes while processes boot in parallel
                $caddy->registerRoute(
                    slug: $info->slug,
                    domain: $domain,
                    port: $spawned['port'],
                    vitePort: $viteJob['port'] ?? null,
                    vitePaths: $viteJob['customPaths'] ?? [],
                    hmrPath: $viteJob['hmrPath'] ?? null,
                );

                // 5. Wait for Octane TCP port (10ms polling; typically already open by now)
                $octane->waitUntilListening($spawned['port'], $spawned['pid'], $spawned['pgid'], $spawned['log']);

                $spawned['db_job'] = $dbJob;

                return $spawned;
            }, $json);

            $dbJob = $spawnedOctane['db_job'] ?? null;

            $state = new WorktreeState(
                slug: $info->slug,
                branch: $info->branch,
                domain: $domain,
                url: $url,
                port: $spawnedOctane['port'],
                pid: $spawnedOctane['pid'],
                pgid: $spawnedOctane['pgid'],
                path: $info->worktreePath,
                parent_path: $info->parentPath,
                created_at: gmdate('c'),
                db_connection: $dbConfig['connection'] ?? null,
                db_name: $dbConfig['database'] ?? null,
                db_pid: $dbJob['pid'] ?? null,
                db_pgid: $dbJob['pgid'] ?? null,
                vite_port: $viteJob['port'] ?? null,
                vite_pid: $viteJob['pid'] ?? null,
                vite_pgid: $viteJob['pgid'] ?? null,
            );

            $stateStore->put($state);
        } catch (Throwable $e) {
            if ($spawnedOctane !== null) {
                $octane->stop($spawnedOctane['pid'], $spawnedOctane['pgid']);
            }
            if ($viteJob !== null) {
                $octane->stop($viteJob['pid'], $viteJob['pgid']);
            }

            if ($json) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            } else {
                error($e->getMessage());
            }

            return self::FAILURE;
        }

        $elapsed = $this->elapsedFormatted();

        if ($json) {
            $payload = array_merge($state->toArray(), [
                'reload_strategy' => $spawnedOctane['reload_strategy'] ?? 'watch',
                'elapsed' => $elapsed,
                'ready_in' => $elapsed,
            ]);
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        if (! $this->option('no-qr')) {
            $this->output->writeln('');
            $this->output->writeln(HalfBlockQrOutput::forUrl($state->url));
        }

        $reloadLabel = match ($spawnedOctane['reload_strategy'] ?? 'stateless-dev') {
            'watch' => 'live --watch',
            'stateless-dev' => 'instant auto-reload (no octane:reload needed)',
            'frankenphp-dev' => 'FrankenPHP php-server (instant dev reload)',
            'artisan-serve' => 'artisan serve (instant dev reload)',
            default => 'prod',
        };

        $details = [
            "URL:     {$state->url}",
            "Branch:  {$state->branch}",
            "Slug:    {$state->slug}",
            "Octane:  127.0.0.1:{$state->port} · PID {$state->pid} ({$reloadLabel})",
        ];

        if ($state->vite_port !== null) {
            $details[] = "Vite:    127.0.0.1:{$state->vite_port} · PID {$state->vite_pid} (bun dev + wss:// HMR in background)";
        }

        if ($state->db_name !== null) {
            $seedLabel = $this->option('no-seed') ? 'migrating' : 'creating + migrating + seeding';
            $details[] = "DB:      {$state->db_name} ({$state->db_connection} · {$seedLabel} in background)";
        }

        $details[] = 'Reboot:  Persisted in ~/.vsl/state.json (auto-restored via vsl resurrect)';

        note(implode("\n", $details));

        outro("Ready in {$elapsed}");

        return self::SUCCESS;
    }
}
