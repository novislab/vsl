<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use App\Services\ProcessManager;
use App\Services\StateStore;
use App\Services\YerdClient;
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
        {--prune : Remove missing or unlinked worktree entries from state}';

    protected $description = 'List all tracked Yerd worktree preview environments and their status';

    public function handle(
        StateStore $stateStore,
        ProcessManager $processManager,
        YerdClient $yerd,
        VslPaths $paths,
    ): int {
        $json = (bool) $this->option('json');
        $prune = (bool) $this->option('prune');
        $this->setupPromptsUi($json);

        $states = $stateStore->all();
        $yerdSites = $states !== [] ? $yerd->listSites() : [];

        $rows = [];
        $jsonItems = [];
        $prunedCount = 0;

        foreach ($states as $state) {
            $dirExists = is_dir($state->path);
            $linkedInYerd = isset($yerdSites[$state->slug]);
            $active = $dirExists && $linkedInYerd;

            $status = match (true) {
                ! $dirExists => 'MISSING',
                $linkedInYerd => 'LINKED',
                default => 'UNLINKED',
            };

            if (! $active && $prune) {
                try {
                    $yerd->unregisterSite($state->slug, $state->vite_proxy_paths);
                } catch (Throwable) {
                    // Best-effort Yerd cleanup during prune
                }

                if ($state->vite_pid !== null && $state->vite_pid > 0) {
                    $processManager->stop($state->vite_pid, $state->vite_pgid ?: $state->vite_pid);
                }

                $stateStore->remove($state->slug);
                foreach ([$paths->logFile($state->slug), $paths->dbLogFile($state->slug), $paths->viteLogFile($state->slug)] as $logFile) {
                    if (is_file($logFile)) {
                        @unlink($logFile);
                    }
                }

                $prunedCount++;

                continue;
            }

            $dbLabel = '-';
            if ($state->db_name !== null) {
                $dbLabel = ($state->db_pid !== null && $processManager->isAlive($state->db_pid))
                    ? "{$state->db_name} (migrating)"
                    : $state->db_name;
            }

            $viteAlive = $state->vite_pid !== null && $processManager->isAlive($state->vite_pid);
            $viteLabel = $state->vite_port !== null
                ? ($viteAlive ? ":{$state->vite_port} (running)" : ":{$state->vite_port} (stopped)")
                : '-';

            $phpLabel = (string) ($yerdSites[$state->slug]['php'] ?? $state->php_version ?? 'default');

            $rows[] = [
                $state->slug,
                $state->branch,
                $status,
                $phpLabel,
                $viteLabel,
                $dbLabel,
                $state->url,
                $state->path,
            ];

            $jsonItems[] = array_merge($state->toArray(), [
                'status' => $status,
                'linked' => $linkedInYerd,
                'db_migrating' => $state->db_pid !== null && $processManager->isAlive($state->db_pid),
                'vite_running' => $viteAlive,
            ]);
        }

        if ($json) {
            $this->line(json_encode($jsonItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

            return self::SUCCESS;
        }

        intro('VSL · Active Worktree Previews (Yerd)');

        if ($rows === []) {
            info($prunedCount > 0
                ? "Pruned {$prunedCount} dead worktree(s). No active previews remain."
                : 'No worktree previews registered.');

            return self::SUCCESS;
        }

        table(
            headers: ['Slug', 'Branch', 'Status', 'PHP', 'Vite', 'DB', 'URL', 'Path'],
            rows: $rows,
        );

        if ($prunedCount > 0) {
            outro("Pruned {$prunedCount} dead worktree(s).");
        }

        return self::SUCCESS;
    }
}
