<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Services\CaddyClient;
use App\Services\OctaneManager;
use App\Services\StateStore;
use App\Services\ViteManager;
use App\Support\VslPaths;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

class ResurrectCommand extends Command
{
    use InteractsWithVslUi;

    protected $signature = 'resurrect
        {--json : Output result as JSON}';

    protected $description = 'Restore active worktree previews, restart dead Octane & Vite workers, and re-register Caddy routes';

    public function handle(
        StateStore $stateStore,
        OctaneManager $octane,
        ViteManager $viteManager,
        CaddyClient $caddy,
        VslPaths $paths,
    ): int {
        $this->startTimer();
        $json = (bool) $this->option('json');
        $this->setupPromptsUi($json);

        intro('VSL · Resurrecting Worktree Previews');

        $states = $stateStore->all();
        $pruned = 0;
        $restarted = 0;
        $restored = 0;
        $errors = [];

        $usedPorts = [];
        $usedVitePorts = [];
        foreach ($states as $state) {
            if (is_dir($state->path) && $octane->isAlive($state->pid)) {
                $usedPorts[] = $state->port;
            }
            if (is_dir($state->path) && $state->vite_port !== null && $state->vite_pid !== null && $octane->isAlive($state->vite_pid)) {
                $usedVitePorts[] = $state->vite_port;
            }
        }

        foreach ($states as $state) {
            if (! is_dir($state->path)) {
                try {
                    $caddy->deleteRoute($state->slug);
                } catch (Throwable) {
                    // Continue pruning missing directory even if route was absent
                }

                $stateStore->remove($state->slug);
                foreach ([$paths->logFile($state->slug), $paths->dbLogFile($state->slug), $paths->viteLogFile($state->slug)] as $log) {
                    if (is_file($log)) {
                        @unlink($log);
                    }
                }

                $pruned++;

                continue;
            }

            try {
                $current = $state;
                $changed = false;

                if (! $octane->isAlive($state->pid)) {
                    $port = $octane->allocatePort($usedPorts, $state->port);
                    $worker = $octane->start($state->path, $state->slug, $port);
                    $usedPorts[] = $worker['port'];

                    $current = $current->withWorker($worker['port'], $worker['pid'], $worker['pgid']);
                    $changed = true;
                    $restarted++;
                }

                $viteConfig = $viteManager->detectConfig($state->path);
                $vitePaths = $viteConfig['customPaths'] ?? [];
                $hmrPath = $viteConfig['hmrPath'] ?? null;

                if ($state->vite_port !== null && $viteConfig !== null && ! $octane->isAlive($state->vite_pid ?? 0)) {
                    $vitePort = $viteManager->allocatePort($usedVitePorts, $state->vite_port);
                    $viteJob = $viteManager->dispatch($state->path, $state->slug, $state->domain, $vitePort);
                    if ($viteJob !== null) {
                        $usedVitePorts[] = $viteJob['port'];
                        $current = $current->withVite($viteJob['port'], $viteJob['pid'], $viteJob['pgid']);
                        $changed = true;
                    }
                }

                if ($changed) {
                    $stateStore->put($current);
                }

                $caddy->registerRoute(
                    slug: $current->slug,
                    domain: $current->domain,
                    port: $current->port,
                    vitePort: $current->vite_port,
                    vitePaths: $vitePaths,
                    hmrPath: $hmrPath,
                );
                $restored++;
            } catch (Throwable $e) {
                $errors[$state->slug] = $e->getMessage();
            }
        }

        $elapsed = $this->elapsedFormatted();

        if ($json) {
            $this->line(json_encode([
                'restored' => $restored,
                'restarted' => $restarted,
                'pruned' => $pruned,
                'errors' => $errors,
                'elapsed' => $elapsed,
                'ready_in' => $elapsed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($errors !== []) {
            foreach ($errors as $slug => $message) {
                error("[{$slug}] {$message}");
            }
        }

        note(implode("\n", [
            "Restored routes:   {$restored}",
            "Restarted workers: {$restarted}",
            "Pruned missing:    {$pruned}",
        ]));

        outro("Ready in {$elapsed}");

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
