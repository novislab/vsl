<?php

namespace App\Services;

use App\Support\VslPaths;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IpResolver
{
    private const IMDS_TOKEN_URL = 'http://169.254.169.254/latest/api/token';

    private const IMDS_IPV4_URL = 'http://169.254.169.254/latest/meta-data/public-ipv4';

    public function __construct(
        private readonly VslPaths $paths = new VslPaths,
    ) {}

    /**
     * Resolve the EC2 instance's public IPv4 address via env, cache, or IMDSv2.
     */
    public function resolve(): string
    {
        $envIp = $this->readEnvIp();
        if ($envIp !== null) {
            return $envIp;
        }

        $cachedIp = $this->readCachedIp();
        if ($cachedIp !== null) {
            return $cachedIp;
        }

        $imdsIp = $this->fetchFromImdsV2();
        $this->writeCachedIp($imdsIp);

        return $imdsIp;
    }

    /**
     * Build the sslip.io domain and HTTPS URL for a worktree slug.
     *
     * @return array{ip: string, domain: string, url: string}
     */
    public function buildPreviewUrl(string $slug, ?string $ip = null): array
    {
        $resolvedIp = $ip ?? $this->resolve();
        $dashed = str_replace('.', '-', $resolvedIp);
        $domain = "{$slug}.{$dashed}.sslip.io";

        return [
            'ip' => $resolvedIp,
            'domain' => $domain,
            'url' => "https://{$domain}",
        ];
    }

    private function readEnvIp(): ?string
    {
        $raw = $_ENV['VSL_PUBLIC_IP'] ?? $_SERVER['VSL_PUBLIC_IP'] ?? getenv('VSL_PUBLIC_IP');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $this->validateIpv4(trim($raw), 'VSL_PUBLIC_IP');
    }

    private function readCachedIp(): ?string
    {
        $configFile = $this->paths->configFile();
        if (! is_file($configFile)) {
            return null;
        }

        $raw = @file_get_contents($configFile);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['public_ip']) || ! is_string($decoded['public_ip'])) {
            return null;
        }

        $ip = trim($decoded['public_ip']);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
    }

    private function writeCachedIp(string $ip): void
    {
        $this->paths->ensureDirectories();
        $configFile = $this->paths->configFile();

        $existing = [];
        if (is_file($configFile)) {
            $decoded = json_decode((string) @file_get_contents($configFile), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $existing['public_ip'] = $ip;
        $existing['updated_at'] = gmdate('c');

        file_put_contents(
            $configFile,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX
        );
    }

    private function fetchFromImdsV2(): string
    {
        try {
            $tokenResponse = Http::connectTimeout(0.5)
                ->timeout(0.5)
                ->withHeaders([
                    'X-aws-ec2-metadata-token-ttl-seconds' => '60',
                ])
                ->put(self::IMDS_TOKEN_URL);

            if (! $tokenResponse->successful()) {
                throw new RuntimeException("IMDSv2 token request failed with HTTP {$tokenResponse->status()}.");
            }

            $token = trim($tokenResponse->body());
            if ($token === '') {
                throw new RuntimeException('IMDSv2 returned an empty metadata token.');
            }

            $ipResponse = Http::connectTimeout(0.5)
                ->timeout(0.5)
                ->withHeaders([
                    'X-aws-ec2-metadata-token' => $token,
                ])
                ->get(self::IMDS_IPV4_URL);

            if (! $ipResponse->successful()) {
                throw new RuntimeException("IMDSv2 public-ipv4 request failed with HTTP {$ipResponse->status()}.");
            }

            return $this->validateIpv4(trim($ipResponse->body()), 'IMDSv2');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Unable to resolve public IPv4 via VSL_PUBLIC_IP, ~/.vsl/config.json, or EC2 IMDSv2: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    private function validateIpv4(string $ip, string $source): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException("Invalid IPv4 address from {$source}: '{$ip}'");
        }

        return $ip;
    }
}
