<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Services\CaddyClient;
use App\Services\OctaneManager;
use App\Services\StateStore;
use App\Support\VslPaths;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

class ListCommand extends Command
{
    use InteractsWithVslUi;

    protected $signature = 'list
        {--json : Output active worktree previews as JSON}
        {--prune : Remove dead worktree entries from state}';

    protected $description = 'List all tracked worktree preview environments and their worker status';

    public function handle(
        StateStore $stateStore,
        OctaneManager $octane,
        CaddyClient $caddy,
        VslPaths $paths,
    ): int {
        $json = (bool) $this->option('json');
        $prune = (bool) $this->option('prune');
        $this->setupPromptsUi($json);

        $states = $stateStore->all();
        $rows = [];
        $jsonItems = [];
        $prunedCount = 0;

        foreach ($states as $state) {
            $alive = $octane->isAlive($state->pid);
            $status = $alive ? 'RUNNING' : 'DEAD';

            if (! $alive && $prune) {
                try {
                    $caddy->deleteRoute($state->slug);
                } catch (Throwable) {
                    // Best-effort Caddy cleanup during prune
                }

                $stateStore->remove($state->slug);
                $logFile = $paths->logFile($state->slug);
                if (is_file($logFile)) {
                    @unlink($logFile);
                }

                $prunedCount++;

                continue;
            }

            $dbLabel = '-';
            if ($state->db_name !== null) {
                $dbLabel = ($state->db_pid !== null && $octane->isAlive($state->db_pid))
                    ? "{$state->db_name} (migrating)"
                    : $state->db_name;
            }

            $portLabel = $state->vite_port !== null
                ? "{$state->port} / :{$state->vite_port}"
                : (string) $state->port;

            $rows[] = [
                $state->slug,
                $state->branch,
                $status,
                $portLabel,
                (string) $state->pid,
                $dbLabel,
                $state->url,
                $state->path,
            ];

            $jsonItems[] = array_merge($state->toArray(), [
                'status' => $status,
                'db_migrating' => $state->db_pid !== null && $octane->isAlive($state->db_pid),
                'vite_running' => $state->vite_pid !== null && $octane->isAlive($state->vite_pid),
            ]);
        }

        if ($json) {
            $this->line(json_encode($jsonItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

            return self::SUCCESS;
        }

        intro('VSL · Active Worktree Previews');

        if ($rows === []) {
            info($prunedCount > 0
                ? "Pruned {$prunedCount} dead worktree(s). No active previews remain."
                : 'No worktree previews registered.');

            return self::SUCCESS;
        }

        table(
            headers: ['Slug', 'Branch', 'Status', 'Port', 'PID', 'DB', 'URL', 'Path'],
            rows: $rows,
        );

        if ($prunedCount > 0) {
            outro("Pruned {$prunedCount} dead worktree(s).");
        }

        return self::SUCCESS;
    }
}
