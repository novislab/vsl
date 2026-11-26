<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithVslUi;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

class InstallServiceCommand extends Command
{
    use InteractsWithVslUi;

    public const UNIT_PATH = '/etc/systemd/system/vsl-resurrect.service';

    protected $signature = 'install-service
        {--user= : Linux user to run the service as (defaults to current user)}
        {--binary= : Path to the vsl binary (defaults to /usr/local/bin/vsl)}
        {--dry-run : Render the systemd unit file without installing or enabling it}';

    protected $description = 'Install and enable the systemd vsl-resurrect service for reboot persistence';

    public function handle(): int
    {
        $this->setupPromptsUi(false);

        intro('VSL · Install systemd Resurrect Service');

        $user = (string) ($this->option('user') ?: $this->detectCurrentUser());
        $binary = (string) ($this->option('binary') ?: '/usr/local/bin/vsl');
        $unitContent = self::buildUnitFile($user, $binary);

        if ($this->option('dry-run')) {
            note($unitContent);
            outro('Dry run complete (no files written).');

            return self::SUCCESS;
        }

        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

        if ($isRoot || is_writable(dirname(self::UNIT_PATH))) {
            $written = @file_put_contents(self::UNIT_PATH, $unitContent);
            if ($written === false) {
                error('Failed to write '.self::UNIT_PATH);

                return self::FAILURE;
            }
        } else {
            $writeResult = Process::input($unitContent)->run(['sudo', 'tee', self::UNIT_PATH]);
            if (! $writeResult->successful()) {
                $err = trim($writeResult->errorOutput()) ?: trim($writeResult->output());
                error('Failed to write '.self::UNIT_PATH." via sudo: {$err}");

                return self::FAILURE;
            }
        }

        $prefix = $isRoot ? [] : ['sudo'];

        $reload = Process::run(array_merge($prefix, ['systemctl', 'daemon-reload']));
        if (! $reload->successful()) {
            $err = trim($reload->errorOutput()) ?: trim($reload->output());
            error("systemctl daemon-reload failed: {$err}");

            return self::FAILURE;
        }

        $enable = Process::run(array_merge($prefix, ['systemctl', 'enable', 'vsl-resurrect.service']));
        if (! $enable->successful()) {
            $err = trim($enable->errorOutput()) ?: trim($enable->output());
            error("systemctl enable failed: {$err}");

            return self::FAILURE;
        }

        note(implode("\n", [
            'Unit:     '.self::UNIT_PATH,
            "User:     {$user}",
            "Binary:   {$binary}",
            'Enabled:  vsl-resurrect.service',
        ]));

        outro('Reboot persistence installed and enabled.');

        return self::SUCCESS;
    }

    public static function buildUnitFile(string $user, string $binary): string
    {
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return <<<INI
[Unit]
Description=VSL Worktree Preview Resurrect Service
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User={$user}
Environment=HOME={$home}
ExecStart={$binary} resurrect
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target

INI;
    }

    private function detectCurrentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $envUser = $_ENV['USER'] ?? $_SERVER['USER'] ?? getenv('USER');
        if (is_string($envUser) && $envUser !== '') {
            return $envUser;
        }

        return get_current_user() ?: 'ubuntu';
    }
}
