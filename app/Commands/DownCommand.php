<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Services\CaddyClient;
use App\Services\DatabaseManager;
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
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class DownCommand extends Command
{
    use InteractsWithVslUi;

    protected $signature = 'down
        {target? : Optional slug, branch, or path}
        {--path= : Path to the Git worktree (defaults to current working directory)}
        {--keep-db : Preserve the isolated database instead of dropping it in the background}
        {--json : Output result as JSON}';

    protected $description = 'Stop Octane & Vite workers, remove the Caddy route, and drop the isolated database for a worktree preview';

    public function handle(
        OctaneManager $octane,
        ViteManager $viteManager,
        CaddyClient $caddy,
        DatabaseManager $databaseManager,
        StateStore $stateStore,
        VslPaths $paths,
    ): int {
        $this->startTimer();
        $json = (bool) $this->option('json');
        $this->setupPromptsUi($json);

        intro('VSL · Worktree Preview Down');

        $targetArg = $this->argument('target');
        $target = is_string($targetArg) && $targetArg !== '' ? $targetArg : null;
        $path = (string) ($this->option('path') ?: getcwd());

        $state = $target !== null
            ? $stateStore->findByTarget($target)
            : $stateStore->findByPath($path);

        if ($state === null && $target === null && ! $json && $this->isInteractiveTty()) {
            $all = $stateStore->all();
            if ($all === []) {
                warning('No active worktree previews found.');

                return self::SUCCESS;
            }

            $options = [];
            foreach ($all as $item) {
                $options[$item->slug] = "{$item->slug} ({$item->branch}) — {$item->url}";
            }

            $selected = (string) select(
                label: 'Select an active worktree preview to tear down',
                options: $options,
            );

            $state = $all[$selected] ?? null;
        }

        if ($state === null) {
            $elapsed = $this->elapsedFormatted();

            if ($target !== null) {
                if ($json) {
                    $this->line(json_encode([
                        'removed' => false,
                        'error' => "No active worktree preview found for '{$target}'.",
                        'elapsed' => $elapsed,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
                } else {
                    error("No active worktree preview found for '{$target}'.");
                }

                return self::FAILURE;
            }

            if ($json) {
                $this->line(json_encode([
                    'removed' => false,
                    'path' => $path,
                    'elapsed' => $elapsed,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            } else {
                warning("No active worktree preview registered for {$path}.");
            }

            return self::SUCCESS;
        }

        $dbDroppedInBg = false;

        try {
            $this->spinStep("Stopping workers (PID {$state->pid}) and removing Caddy route...", function () use ($octane, $viteManager, $caddy, $databaseManager, $stateStore, $paths, $state, &$dbDroppedInBg): void {
                if ($state->db_pid !== null && $state->db_pid > 0) {
                    $octane->stop($state->db_pid, $state->db_pgid ?: $state->db_pid);
                }

                $viteManager->cleanup($state->path, $state->slug, $state->vite_pid, $state->vite_pgid, $octane);
                $octane->stop($state->pid, $state->pgid);
                $caddy->deleteRoute($state->slug);
                $stateStore->remove($state->slug);

                if (! $this->option('keep-db') && $state->db_connection !== null && $state->db_name !== null) {
                    $databaseManager->dispatchTeardown($state);
                    $dbDroppedInBg = true;
                }

                $logFile = $paths->logFile($state->slug);
                if (is_file($logFile)) {
                    @unlink($logFile);
                }
            }, $json);
        } catch (Throwable $e) {
            if ($json) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            } else {
                error($e->getMessage());
            }

            return self::FAILURE;
        }

        $elapsed = $this->elapsedFormatted();

        if ($json) {
            $this->line(json_encode([
                'removed' => true,
                'slug' => $state->slug,
                'branch' => $state->branch,
                'url' => $state->url,
                'port' => $state->port,
                'vite_port' => $state->vite_port,
                'db_name' => $state->db_name,
                'db_teardown_dispatched' => $dbDroppedInBg,
                'elapsed' => $elapsed,
                'ready_in' => $elapsed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $lines = [
            "Stopped: {$state->slug} ({$state->url})",
            "Octane:  PID {$state->pid} / port {$state->port} terminated",
        ];

        if ($state->vite_port !== null) {
            $lines[] = "Vite:    PID {$state->vite_pid} / port {$state->vite_port} terminated & public/hot removed";
        }

        if ($dbDroppedInBg) {
            $lines[] = "DB:      Dropping {$state->db_name} ({$state->db_connection}) in background";
        }

        $lines[] = "Disk:    Worktree preserved at {$state->path}";

        note(implode("\n", $lines));

        outro("Ready in {$elapsed}");

        return self::SUCCESS;
    }
}
