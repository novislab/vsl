<?php

namespace App\Support;

class VslPaths
{
    public function __construct(
        private readonly ?string $baseDir = null,
    ) {}

    /**
     * Resolve the base ~/.vsl directory (honoring VSL_HOME when set).
     */
    public function baseDir(): string
    {
        if ($this->baseDir !== null && $this->baseDir !== '') {
            return rtrim($this->baseDir, '/');
        }

        $vslHome = $_ENV['VSL_HOME'] ?? $_SERVER['VSL_HOME'] ?? getenv('VSL_HOME');
        if (is_string($vslHome) && $vslHome !== '') {
            return rtrim($vslHome, '/');
        }

        $home = $_ENV['HOME'] ?? $_SERVER['HOME'] ?? getenv('HOME');
        if (! is_string($home) || $home === '') {
            $passwd = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? posix_getpwuid(posix_geteuid())
                : false;
            $home = is_array($passwd) && isset($passwd['dir']) ? $passwd['dir'] : sys_get_temp_dir();
        }

        return rtrim($home, '/').'/.vsl';
    }

    public function stateFile(): string
    {
        return $this->baseDir().'/state.json';
    }

    public function stateLockFile(): string
    {
        return $this->baseDir().'/state.lock';
    }

    public function configFile(): string
    {
        return $this->baseDir().'/config.json';
    }

    public function logsDir(): string
    {
        return $this->baseDir().'/logs';
    }

    public function logFile(string $slug): string
    {
        return $this->logsDir().'/'.$slug.'.log';
    }

    public function dbLogFile(string $slug): string
    {
        return $this->logsDir().'/'.$slug.'-db.log';
    }

    public function viteDir(): string
    {
        return $this->baseDir().'/vite';
    }

    public function viteLogFile(string $slug): string
    {
        return $this->logsDir().'/'.$slug.'-vite.log';
    }

    /**
     * Ensure the base, logs, and vite directories exist.
     */
    public function ensureDirectories(): void
    {
        foreach ([$this->baseDir(), $this->logsDir(), $this->viteDir()] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Unable to create directory: {$dir}");
            }
        }
    }
}
