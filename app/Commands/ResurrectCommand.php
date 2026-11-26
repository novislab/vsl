<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Services\ProcessManager;
use App\Services\StateStore;
use App\Services\ViteManager;
use App\Services\YerdClient;
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

    protected $description = 'Restore tracked Yerd .test worktree previews, prune missing directories, and restart dead Vite workers';

    public function handle(
        StateStore $stateStore,
        ProcessManager $processManager,
        ViteManager $viteManager,
        YerdClient $yerd,
        VslPaths $paths,
    ): int {
        $this->startTimer();
        $json = (bool) $this->option('json');
        $this->setupPromptsUi($json);

        intro('VSL · Resurrecting Worktree Previews (Yerd)');

        $states = $stateStore->all();
        $pruned = 0;
        $restarted = 0;
        $restored = 0;
        $errors = [];

        $usedVitePorts = [];
        foreach ($states as $state) {
            if (is_dir($state->path) && $state->vite_port !== null && $state->vite_pid !== null && $processManager->isAlive($state->vite_pid)) {
                $usedVitePorts[] = $state->vite_port;
            }
        }

        foreach ($states as $state) {
            if (! is_dir($state->path)) {
                try {
                    $yerd->unregisterSite($state->slug, $state->vite_proxy_paths);
                } catch (Throwable) {
                    // Continue pruning missing directory even if site was already unlinked
                }

                if ($state->vite_pid !== null && $state->vite_pid > 0) {
                    $processManager->stop($state->vite_pid, $state->vite_pgid ?: $state->vite_pid);
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

                $viteConfig = $viteManager->detectConfig($state->path);
                $vitePaths = $viteConfig['customPaths'] ?? [];
                $hmrPath = $viteConfig['hmrPath'] ?? null;

                if ($state->vite_port !== null && $viteConfig !== null && ! $processManager->isAlive($state->vite_pid ?? 0)) {
                    $vitePort = $viteManager->allocatePort($usedVitePorts, $state->vite_port);
                    $viteJob = $viteManager->dispatch($state->path, $state->slug, $state->domain, $vitePort, $state->secure);
                    if ($viteJob !== null) {
                        $usedVitePorts[] = $viteJob['port'];
                        $hmrPath = $viteJob['hmrPath'];
                        $current = $current->withVite($viteJob['port'], $viteJob['pid'], $viteJob['pgid']);
                        $changed = true;
                        $restarted++;
                    }
                }

                $proxyPaths = $yerd->registerSite(
                    slug: $current->slug,
                    worktreePath: $current->path,
                    secure: $current->secure,
                    phpVersion: $current->php_version,
                    vitePort: $current->vite_port,
                    vitePaths: $vitePaths,
                    hmrPath: $hmrPath,
                );

                if ($proxyPaths !== $current->vite_proxy_paths) {
                    $current = $current->withVite($current->vite_port, $current->vite_pid, $current->vite_pgid, $proxyPaths);
                    $changed = true;
                }

                if ($changed) {
                    $stateStore->put($current);
                }

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
            "Restored sites:    {$restored}",
            "Restarted Vite:    {$restarted}",
            "Pruned missing:    {$pruned}",
        ]));

        outro("Ready in {$elapsed}");

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
