<?php

namespace App\Services;

use App\Data\WorktreeState;
use App\Support\VslPaths;
use Illuminate\Support\Facades\Process;
use PDO;
use Phar;
use RuntimeException;

class DatabaseManager
{
    public function __construct(
        private readonly VslPaths $paths = new VslPaths,
    ) {}

    /**
     * Scan the worktree's .env for a supported DB_CONNECTION, rewrite DB_DATABASE
     * to an isolated worktree database name/path, and return resolved config.
     *
     * @return array{
     *     connection: string,
     *     database: string,
     *     host: string,
     *     port: int,
     *     username: string,
     *     password: string
     * }|null
     */
    public function prepareEnv(string $worktreePath, string $slug): ?array
    {
        $envPath = rtrim($worktreePath, '/').'/.env';
        if (! is_file($envPath)) {
            return null;
        }

        $contents = file_get_contents($envPath);
        if (! is_string($contents)) {
            return null;
        }

        $env = self::parseEnv($contents);
        $driver = self::normalizeDriver($env['DB_CONNECTION'] ?? '');
        if ($driver === null) {
            return null;
        }

        if ($driver === 'sqlite') {
            $currentDb = $env['DB_DATABASE'] ?? '';
            if ($currentDb === ':memory:') {
                return null;
            }

            $dbName = rtrim($worktreePath, '/').'/database/vsl_'.str_replace('-', '_', $slug).'.sqlite';
            $updated = self::rewriteEnvKey($contents, 'DB_DATABASE', $dbName);
            file_put_contents($envPath, $updated);

            return [
                'connection' => 'sqlite',
                'database' => $dbName,
                'host' => '',
                'port' => 0,
                'username' => '',
                'password' => '',
            ];
        }

        $dbName = self::databaseNameForSlug($slug);
        $updated = self::rewriteEnvKey($contents, 'DB_DATABASE', $dbName);
        file_put_contents($envPath, $updated);

        return [
            'connection' => $driver,
            'database' => $dbName,
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($env['DB_PORT'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            'username' => $env['DB_USERNAME'] ?? ($driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => $env['DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Spawn a detached out-of-terminal background job to create, migrate, and seed the database.
     *
     * @return array{pid: int, pgid: int, log: string}
     */
    public function dispatchProvision(string $worktreePath, string $slug, bool $seed = true): array
    {
        $this->paths->ensureDirectories();
        $logFile = $this->paths->dbLogFile($slug);

        $args = ['db:provision', $slug, '--path='.$worktreePath];
        if (! $seed) {
            $args[] = '--no-seed';
        }

        $selfCmd = self::buildSelfCommand($args);
        $shellCmd = sprintf(
            'setsid %s > %s 2>&1 < /dev/null & echo $!',
            $selfCmd,
            escapeshellarg($logFile)
        );

        $result = Process::path($worktreePath)->run(['bash', '-c', $shellCmd]);
        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("Failed to dispatch background DB provision job: {$error}");
        }

        $pid = (int) trim($result->output());
        $pgid = $pid > 0 && function_exists('posix_getpgid') ? (posix_getpgid($pid) ?: $pid) : $pid;

        return [
            'pid' => $pid,
            'pgid' => $pgid,
            'log' => $logFile,
        ];
    }

    /**
     * Spawn a detached out-of-terminal background job to drop the worktree's isolated database.
     *
     * @return array{pid: int, pgid: int}|null
     */
    public function dispatchTeardown(WorktreeState $state): ?array
    {
        if ($state->db_connection === null || $state->db_name === null) {
            return null;
        }

        $this->paths->ensureDirectories();
        $logFile = $this->paths->dbLogFile($state->slug);

        $args = [
            'db:teardown',
            $state->slug,
            '--connection='.$state->db_connection,
            '--database='.$state->db_name,
            '--env-file='.rtrim($state->path, '/').'/.env',
            '--fallback-env-file='.rtrim($state->parent_path, '/').'/.env',
        ];

        $selfCmd = self::buildSelfCommand($args);
        $shellCmd = sprintf(
            'setsid %s > %s 2>&1 < /dev/null & echo $!',
            $selfCmd,
            escapeshellarg($logFile)
        );

        $cwd = is_dir($state->parent_path) ? $state->parent_path : sys_get_temp_dir();
        $result = Process::path($cwd)->run(['bash', '-c', $shellCmd]);

        if (! $result->successful()) {
            return null;
        }

        $pid = (int) trim($result->output());
        $pgid = $pid > 0 && function_exists('posix_getpgid') ? (posix_getpgid($pid) ?: $pid) : $pid;

        return [
            'pid' => $pid,
            'pgid' => $pgid,
        ];
    }

    /**
     * Execute database creation, migration, and seeding (intended to run inside the background job).
     */
    public function provision(string $worktreePath, bool $seed = true): void
    {
        $envPath = rtrim($worktreePath, '/').'/.env';
        if (! is_file($envPath)) {
            throw new RuntimeException("Missing .env file in {$worktreePath}");
        }

        $env = self::parseEnv((string) file_get_contents($envPath));
        $driver = self::normalizeDriver($env['DB_CONNECTION'] ?? '');
        $database = $env['DB_DATABASE'] ?? '';

        if ($driver === null || $database === '') {
            return;
        }

        $this->createDatabase(
            driver: $driver,
            database: $database,
            host: $env['DB_HOST'] ?? '127.0.0.1',
            port: (int) ($env['DB_PORT'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            username: $env['DB_USERNAME'] ?? ($driver === 'pgsql' ? 'postgres' : 'root'),
            password: $env['DB_PASSWORD'] ?? '',
        );

        $artisanCmd = ['php', 'artisan', 'migrate', '--force'];
        if ($seed) {
            $artisanCmd[] = '--seed';
        }

        $result = Process::path($worktreePath)->timeout(300)->run($artisanCmd);
        if (! $result->successful()) {
            $err = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("Artisan migrate failed in {$worktreePath}: {$err}");
        }

        $output = trim($result->output());
        if ($output !== '') {
            echo $output."\n";
        }
    }

    /**
     * Drop an isolated worktree database (intended to run inside the background teardown job).
     */
    public function teardown(
        string $connection,
        string $database,
        ?string $envFile = null,
        ?string $fallbackEnvFile = null,
    ): void {
        $driver = self::normalizeDriver($connection);
        if ($driver === null || $database === '') {
            return;
        }

        if ($driver === 'sqlite') {
            foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            return;
        }

        $env = [];
        foreach ([$fallbackEnvFile, $envFile] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                $env = array_merge($env, self::parseEnv((string) file_get_contents($candidate)));
            }
        }

        $this->dropDatabase(
            driver: $driver,
            database: $database,
            host: $env['DB_HOST'] ?? '127.0.0.1',
            port: (int) ($env['DB_PORT'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            username: $env['DB_USERNAME'] ?? ($driver === 'pgsql' ? 'postgres' : 'root'),
            password: $env['DB_PASSWORD'] ?? '',
        );
    }

    public function createDatabase(
        string $driver,
        string $database,
        string $host,
        int $port,
        string $username,
        string $password,
    ): void {
        if ($driver === 'sqlite') {
            $dir = dirname($database);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Failed to create SQLite directory: {$dir}");
            }

            if (! is_file($database) && ! @touch($database)) {
                throw new RuntimeException("Failed to create SQLite database file: {$database}");
            }

            return;
        }

        $safeDb = self::assertSafeIdentifier($database);

        if ($driver === 'mysql') {
            $sql = "CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";

            if ($this->hasBinary('mysql')) {
                $this->runMysql($host, $port, $username, $password, $sql);

                return;
            }

            $this->runPdo("mysql:host={$host};port={$port}", $username, $password, $sql);

            return;
        }

        if ($driver === 'pgsql') {
            if ($this->hasBinary('psql')) {
                $exists = $this->runPsql(
                    $host,
                    $port,
                    $username,
                    $password,
                    "SELECT 1 FROM pg_database WHERE datname = '{$safeDb}';",
                    tuplesOnly: true,
                );

                if (trim($exists) !== '1') {
                    $this->runPsql($host, $port, $username, $password, "CREATE DATABASE \"{$safeDb}\";");
                }

                return;
            }

            $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $stmt->execute([$safeDb]);
            if ($stmt->fetchColumn() === false) {
                $pdo->exec("CREATE DATABASE \"{$safeDb}\"");
            }
        }
    }

    public function dropDatabase(
        string $driver,
        string $database,
        string $host,
        int $port,
        string $username,
        string $password,
    ): void {
        $safeDb = self::assertSafeIdentifier($database);

        if ($driver === 'mysql') {
            $sql = "DROP DATABASE IF EXISTS `{$safeDb}`;";

            if ($this->hasBinary('mysql')) {
                $this->runMysql($host, $port, $username, $password, $sql);

                return;
            }

            $this->runPdo("mysql:host={$host};port={$port}", $username, $password, $sql);

            return;
        }

        if ($driver === 'pgsql') {
            $terminateSql = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$safeDb}' AND pid <> pg_backend_pid();";
            $dropSql = "DROP DATABASE IF EXISTS \"{$safeDb}\";";

            if ($this->hasBinary('psql')) {
                $this->runPsql($host, $port, $username, $password, $terminateSql);
                $this->runPsql($host, $port, $username, $password, $dropSql);

                return;
            }

            $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec($terminateSql);
            $pdo->exec($dropSql);
        }
    }

    public static function normalizeDriver(string $connection): ?string
    {
        return match (strtolower(trim($connection))) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'sqlite3' => 'sqlite',
            default => null,
        };
    }

    /**
     * Build a safe database identifier for MySQL (max 64) and PostgreSQL (max 63).
     */
    public static function databaseNameForSlug(string $slug): string
    {
        $normalized = strtolower($slug);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        $name = 'vsl_'.($normalized !== '' ? $normalized : 'worktree');

        if (strlen($name) > 63) {
            $name = rtrim(substr($name, 0, 63), '_');
        }

        return $name;
    }

    /**
     * Parse key=value pairs from a .env file.
     *
     * @return array<string, string>
     */
    public static function parseEnv(string $contents): array
    {
        $vars = [];
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            $rawValue = trim(substr($trimmed, $eqPos + 1));

            if ($rawValue === '') {
                $vars[$key] = '';

                continue;
            }

            $first = $rawValue[0];
            $last = $rawValue[strlen($rawValue) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $vars[$key] = substr($rawValue, 1, -1);

                continue;
            }

            // Strip inline comments from unquoted values
            $commentPos = strpos($rawValue, ' #');
            if ($commentPos !== false) {
                $rawValue = rtrim(substr($rawValue, 0, $commentPos));
            }

            $vars[$key] = $rawValue;
        }

        return $vars;
    }

    /**
     * Rewrite an existing KEY=... line in .env content or append it if absent.
     */
    public static function rewriteEnvKey(string $envContent, string $key, string $value): string
    {
        $formattedValue = preg_match('/\s|#/', $value) === 1
            ? '"'.str_replace('"', '\\"', $value).'"'
            : $value;

        $line = "{$key}={$formattedValue}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $envContent) === 1) {
            return preg_replace($pattern, $line, $envContent, 1) ?? $envContent;
        }

        $trimmed = rtrim($envContent, "\r\n");

        return $trimmed === '' ? $line."\n" : $trimmed."\n".$line."\n";
    }

    /**
     * Build a shell-escaped invocation of the current vsl binary/script.
     *
     * @param  array<int, string>  $args
     */
    public static function buildSelfCommand(array $args): string
    {
        $escapedArgs = implode(' ', array_map('escapeshellarg', $args));

        $pharPath = class_exists(Phar::class) ? Phar::running(false) : '';
        $binaryName = basename(PHP_BINARY);

        // Compiled static-php-cli micro binary: PHP_BINARY itself is the executable
        if (! str_contains($binaryName, 'php')) {
            return escapeshellarg(PHP_BINARY).' '.$escapedArgs;
        }

        $script = $pharPath !== '' ? $pharPath : (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: base_path('vsl'));

        return escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.$escapedArgs;
    }

    private static function assertSafeIdentifier(string $database): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException("Unsafe database identifier rejected: '{$database}'");
        }

        return $database;
    }

    private function hasBinary(string $binary): bool
    {
        $result = Process::run(['which', $binary]);

        return $result->successful() && trim($result->output()) !== '';
    }

    private function runMysql(string $host, int $port, string $username, string $password, string $sql): void
    {
        $cmd = [
            'mysql',
            '--host='.$host,
            '--port='.(string) $port,
            '--user='.$username,
            '--execute='.$sql,
        ];

        $process = $password !== ''
            ? Process::env(['MYSQL_PWD' => $password])
            : Process::getFacadeRoot();

        $result = $process->run($cmd);

        if (! $result->successful()) {
            $err = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("MySQL command failed: {$err}");
        }
    }

    private function runPsql(
        string $host,
        int $port,
        string $username,
        string $password,
        string $sql,
        bool $tuplesOnly = false,
    ): string {
        $cmd = [
            'psql',
            '-h', $host,
            '-p', (string) $port,
            '-U', $username,
            '-d', 'postgres',
            '-v', 'ON_ERROR_STOP=1',
        ];

        if ($tuplesOnly) {
            $cmd[] = '-t';
            $cmd[] = '-A';
        }

        $cmd[] = '-c';
        $cmd[] = $sql;

        $process = $password !== ''
            ? Process::env(['PGPASSWORD' => $password])
            : Process::getFacadeRoot();

        $result = $process->run($cmd);

        if (! $result->successful()) {
            $err = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("PostgreSQL command failed: {$err}");
        }

        return $result->output();
    }

    private function runPdo(string $dsn, string $username, string $password, string $sql): void
    {
        $driver = str_starts_with($dsn, 'pgsql:') ? 'pdo_pgsql' : 'pdo_mysql';

        if (extension_loaded($driver)) {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec($sql);

            return;
        }

        // Static micro binary may omit PDO drivers; delegate to host PHP with env-passed credentials
        $script = '$pdo = new PDO(getenv("VSL_DSN"), getenv("VSL_DB_USER"), getenv("VSL_DB_PASS"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $pdo->exec(getenv("VSL_DB_SQL"));';
        $result = Process::env([
            'VSL_DSN' => $dsn,
            'VSL_DB_USER' => $username,
            'VSL_DB_PASS' => $password,
            'VSL_DB_SQL' => $sql,
        ])->run(['php', '-r', $script]);

        if (! $result->successful()) {
            $err = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("PDO database command failed: {$err}");
        }
    }
}
