<?php

use App\Services\IpResolver;
use App\Support\VslPaths;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('resolves public IPv4 from IMDSv2, caches it in config.json, and builds sslip.io URL', function () {
    $base = sys_get_temp_dir().'/vsl-ip-'.uniqid('', true);
    $prevEnv = getenv('VSL_PUBLIC_IP');
    putenv('VSL_PUBLIC_IP');
    unset($_ENV['VSL_PUBLIC_IP'], $_SERVER['VSL_PUBLIC_IP']);

    Http::fake([
        'http://169.254.169.254/latest/api/token' => Http::response('imds-token-123', 200),
        'http://169.254.169.254/latest/meta-data/public-ipv4' => Http::response('54.12.34.56', 200),
    ]);

    try {
        $paths = new VslPaths($base);
        $resolver = new IpResolver($paths);

        $preview = $resolver->buildPreviewUrl('feat-api');

        expect($preview)->toBe([
            'ip' => '54.12.34.56',
            'domain' => 'feat-api.54-12-34-56.sslip.io',
            'url' => 'https://feat-api.54-12-34-56.sslip.io',
        ]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->hasHeader('X-aws-ec2-metadata-token-ttl-seconds', '60'));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->hasHeader('X-aws-ec2-metadata-token', 'imds-token-123'));

        // Subsequent call uses cached config.json without hitting IMDS again
        Http::fake();
        expect($resolver->resolve())->toBe('54.12.34.56');
        Http::assertNothingSent();

        // VSL_PUBLIC_IP overrides cache
        $_ENV['VSL_PUBLIC_IP'] = '198.51.100.7';
        expect($resolver->resolve())->toBe('198.51.100.7');
    } finally {
        unset($_ENV['VSL_PUBLIC_IP'], $_SERVER['VSL_PUBLIC_IP']);
        if ($prevEnv !== false) {
            putenv("VSL_PUBLIC_IP={$prevEnv}");
        }
        exec('rm -rf '.escapeshellarg($base));
    }
});
