<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Data\WorktreeState;
use App\Services\DatabaseManager;
use App\Services\GitInspector;
use App\Services\ProcessManager;
use App\Services\StateStore;
use App\Services\ViteManager;
use App\Services\WorkspaceHydrator;
use App\Services\YerdClient;
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
        {--no-https : Serve the Yerd .test site over HTTP instead of HTTPS}
        {--php= : Pin a specific PHP version in Yerd (defaults to parent site PHP version or Yerd global default)}
        {--json : Output result as JSON}';

    protected $description = 'Hydrate a Git worktree, link & secure a local Yerd .test site, and start Vite HMR + isolated DB in the background';

    public function handle(
        GitInspector $gitInspector,
        YerdClient $yerd,
        WorkspaceHydrator $hydrator,
        DatabaseManager $databaseManager,
        ViteManager $viteManager,
        ProcessManager $processManager,
        StateStore $stateStore,
    ): int {
        $this->startTimer();
        $json = (bool) $this->option('json');
        $this->setupPromptsUi($json);

        $targetPath = (string) ($this->option('path') ?: getcwd());

        intro('VSL · Worktree Preview Up (Yerd)');

        $viteJob = null;
        $dbJob = null;
        $registeredSlug = null;
        $viteProxyPaths = [];

        try {
            $info = $gitInspector->inspect($targetPath);
            $secure = ! $this->option('no-https');
            $preview = $yerd->buildPreviewUrl($info->slug, $secure);
            $domain = $preview['domain'];
            $url = $preview['url'];

            $allStates = $stateStore->all();

            // Idempotency: tear down any existing Vite/DB/Yerd site for this slug first
            if (isset($allStates[$info->slug])) {
                $existing = $allStates[$info->slug];
                $this->spinStep("Replacing existing preview for {$info->slug}...", function () use ($existing, $processManager, $viteManager, $yerd, $stateStore): void {
                    if ($existing->db_pid !== null && $existing->db_pid > 0) {
                        $processManager->stop($existing->db_pid, $existing->db_pgid ?: $existing->db_pid);
                    }
                    $viteManager->cleanup($existing->path, $existing->slug, $existing->vite_pid, $existing->vite_pgid, $processManager);
                    $yerd->unregisterSite($existing->slug, $existing->vite_proxy_paths);
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

            $reservedVitePorts = [];
            foreach ($allStates as $s) {
                if ($s->vite_port !== null) {
                    $reservedVitePorts[] = $s->vite_port;
                }
            }

            $phpOption = $this->option('php');
            $phpVersion = is_string($phpOption) && trim($phpOption) !== ''
                ? trim($phpOption)
                : $yerd->detectParentPhpVersion($info->parentPath);

            // Start Vite HMR, dispatch DB provision, and link + secure the .test site in Yerd
            $this->spinStep("Linking {$domain} in Yerd & starting background workers...", function () use (
                $yerd,
                $viteManager,
                $databaseManager,
                $info,
                $domain,
                $secure,
                $phpVersion,
                $reservedVitePorts,
                $dbConfig,
                &$viteJob,
                &$dbJob,
                &$registeredSlug,
                &$viteProxyPaths,
            ): void {
                // 1. Spawn bun dev (Vite HMR) out of terminal if configured
                if (! $this->option('no-vite') && $viteManager->detectConfig($info->worktreePath) !== null) {
                    $vitePort = $viteManager->allocatePort($reservedVitePorts);
                    $viteJob = $viteManager->dispatch($info->worktreePath, $info->slug, $domain, $vitePort, $secure);
                }

                // 2. Spawn background DB create + migrate + seed out of terminal
                if ($dbConfig !== null) {
                    $dbJob = $databaseManager->dispatchProvision(
                        worktreePath: $info->worktreePath,
                        slug: $info->slug,
                        seed: ! $this->option('no-seed'),
                    );
                }

                // 3. Link, pin PHP version, secure, and attach Vite path proxies in Yerd
                $viteProxyPaths = $yerd->registerSite(
                    slug: $info->slug,
                    worktreePath: $info->worktreePath,
                    secure: $secure,
                    phpVersion: $phpVersion,
                    vitePort: $viteJob['port'] ?? null,
                    vitePaths: $viteJob['customPaths'] ?? [],
                    hmrPath: $viteJob['hmrPath'] ?? null,
                );
                $registeredSlug = $info->slug;
            }, $json);

            $state = new WorktreeState(
                slug: $info->slug,
                branch: $info->branch,
                domain: $domain,
                url: $url,
                path: $info->worktreePath,
                parent_path: $info->parentPath,
                created_at: gmdate('c'),
                secure: $secure,
                php_version: $phpVersion,
                db_connection: $dbConfig['connection'] ?? null,
                db_name: $dbConfig['database'] ?? null,
                db_pid: $dbJob['pid'] ?? null,
                db_pgid: $dbJob['pgid'] ?? null,
                vite_port: $viteJob['port'] ?? null,
                vite_pid: $viteJob['pid'] ?? null,
                vite_pgid: $viteJob['pgid'] ?? null,
                vite_proxy_paths: $viteProxyPaths,
            );

            $stateStore->put($state);
        } catch (Throwable $e) {
            if ($viteJob !== null) {
                $processManager->stop($viteJob['pid'], $viteJob['pgid']);
            }
            if ($registeredSlug !== null) {
                try {
                    $yerd->unregisterSite($registeredSlug, $viteProxyPaths);
                } catch (Throwable) {
                    // Best-effort rollback
                }
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

        $yerdSummary = $state->secure ? 'linked · HTTPS (trusted CA)' : 'linked · HTTP';
        if ($state->php_version !== null) {
            $yerdSummary .= " · PHP {$state->php_version}";
        }

        $details = [
            "URL:     {$state->url}",
            "Branch:  {$state->branch}",
            "Slug:    {$state->slug}",
            "Yerd:    {$yerdSummary}",
        ];

        if ($state->vite_port !== null) {
            $hmrProto = $state->secure ? 'wss://' : 'ws://';
            $details[] = "Vite:    127.0.0.1:{$state->vite_port} · PID {$state->vite_pid} (bun dev + {$hmrProto} HMR via Yerd proxy)";
        }

        if ($state->db_name !== null) {
            $seedLabel = $this->option('no-seed') ? 'migrating' : 'creating + migrating + seeding';
            $details[] = "DB:      {$state->db_name} ({$state->db_connection} · {$seedLabel} in background)";
        }

        $details[] = 'State:   Persisted in ~/.vsl/state.json (restorable via vsl resurrect)';

        note(implode("\n", $details));

        outro("Ready in {$elapsed}");

        return self::SUCCESS;
    }
}
