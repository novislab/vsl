<?php

namespace App\Commands;

use App\Services\DatabaseManager;
use App\Support\VslPaths;
use LaravelZero\Framework\Commands\Command;

class DbTeardownCommand extends Command
{
    protected $signature = 'db:teardown
        {slug : Worktree slug}
        {--connection= : Database connection driver}
        {--database= : Isolated database name or path}
        {--env-file= : Primary .env file path}
        {--fallback-env-file= : Fallback parent repo .env file path}';

    protected $description = 'Internal background worker: drop an isolated worktree database';

    protected $hidden = true;

    public function handle(DatabaseManager $db, VslPaths $paths): int
    {
        $slug = (string) $this->argument('slug');
        $connection = (string) $this->option('connection');
        $database = (string) $this->option('database');
        $envFile = $this->option('env-file');
        $fallbackEnvFile = $this->option('fallback-env-file');

        $db->teardown(
            connection: $connection,
            database: $database,
            envFile: is_string($envFile) ? $envFile : null,
            fallbackEnvFile: is_string($fallbackEnvFile) ? $fallbackEnvFile : null,
        );

        $dbLog = $paths->dbLogFile($slug);
        if (is_file($dbLog)) {
            @unlink($dbLog);
        }

        return self::SUCCESS;
    }
}
