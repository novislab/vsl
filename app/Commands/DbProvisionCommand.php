<?php

namespace App\Commands;

use App\Services\DatabaseManager;
use LaravelZero\Framework\Commands\Command;

class DbProvisionCommand extends Command
{
    protected $signature = 'db:provision
        {slug : Worktree slug}
        {--path= : Path to the worktree}
        {--no-seed : Skip running db:seed after migrate}';

    protected $description = 'Internal background worker: create, migrate, and seed a worktree database';

    protected $hidden = true;

    public function handle(DatabaseManager $db): int
    {
        $slug = (string) $this->argument('slug');
        $path = (string) ($this->option('path') ?: getcwd());
        $seed = ! $this->option('no-seed');

        $this->line(sprintf('[%s] Provisioning database for %s...', gmdate('c'), $slug));
        $db->provision($path, $seed);
        $this->line(sprintf('[%s] Database ready for %s.', gmdate('c'), $slug));

        return self::SUCCESS;
    }
}
