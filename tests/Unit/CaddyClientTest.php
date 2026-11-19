<?php

use App\Services\CaddyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('bootstraps srv0 if missing, deletes old route, and posts reverse proxy route', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            $request->method() === 'GET' && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response([], 404),
            $request->method() === 'PUT' && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response([], 200),
            $request->method() === 'DELETE' && $request->url() === 'http://127.0.0.1:2019/id/vsl-feat-demo' => Http::response([], 404),
            $request->method() === 'POST' && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
            default => Http::response([], 500),
        };
    });

    $client = new CaddyClient('http://127.0.0.1:2019');
    $client->registerRoute('feat-demo', 'feat-demo.203-0-113-10.sslip.io', 9102);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0'
            && $request['listen'] === [':80', ':443'];
    });

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'http://127.0.0.1:2019/id/vsl-feat-demo';
    });

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes') {
            return false;
        }

        $body = $request->data();

        return ($body['@id'] ?? null) === 'vsl-feat-demo'
            && ($body['match'][0]['host'][0] ?? null) === 'feat-demo.203-0-113-10.sslip.io'
            && ($body['handle'][0]['handler'] ?? null) === 'subroute'
            && ($body['handle'][0]['routes'][0]['handle'][0]['handler'] ?? null) === 'reverse_proxy'
            && ($body['handle'][0]['routes'][0]['handle'][0]['upstreams'][0]['dial'] ?? null) === '127.0.0.1:9102'
            && ($body['terminal'] ?? false) === true;
    });
});

it('ignores 404 when deleting a Caddy route', function () {
    Http::fake([
        'http://127.0.0.1:2019/id/vsl-missing' => Http::response([], 404),
    ]);

    $client = new CaddyClient('http://127.0.0.1:2019');
    $client->deleteRoute('missing');

    Http::assertSentCount(1);
});

it('bootstraps /config/apps/http when Caddy returns 400 invalid traversal path', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            $request->method() === 'GET' && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['error' => 'invalid traversal path at: config/apps/http'], 400),
            $request->method() === 'PUT' && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['error' => 'invalid traversal path at: config/apps/http'], 400),
            $request->method() === 'PUT' && $request->url() === 'http://127.0.0.1:2019/config/apps/http' => Http::response([], 200),
            $request->method() === 'DELETE' && $request->url() === 'http://127.0.0.1:2019/id/vsl-fresh' => Http::response([], 404),
            $request->method() === 'POST' && $request->url() === 'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
            default => Http::response([], 500),
        };
    });

    $client = new CaddyClient('http://127.0.0.1:2019');
    $client->registerRoute('fresh', 'fresh.13-61-22-195.sslip.io', 9100);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'http://127.0.0.1:2019/config/apps/http');
});

it('registers Vite asset and WSS HMR WebSocket subroutes when vitePort is provided', function () {
    Http::fake([
        'http://127.0.0.1:2019/config/apps/http/servers/srv0' => Http::response(['listen' => [':80', ':443']], 200),
        'http://127.0.0.1:2019/id/vsl-feat-hmr' => Http::response([], 404),
        'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes' => Http::response([], 200),
    ]);

    $client = new CaddyClient('http://127.0.0.1:2019');
    $client->registerRoute(
        slug: 'feat-hmr',
        domain: 'feat-hmr.13-61-22-195.sslip.io',
        port: 9100,
        vitePort: 5173,
        vitePaths: ['/src/*'],
        hmrPath: '/custom-hmr',
    );

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'http://127.0.0.1:2019/config/apps/http/servers/srv0/routes') {
            return false;
        }

        $routes = $request['handle'][0]['routes'] ?? [];

        return count($routes) === 3
            && in_array('/@vite/*', $routes[0]['match'][0]['path'], true)
            && in_array('/src/*', $routes[0]['match'][0]['path'], true)
            && in_array('/custom-hmr', $routes[0]['match'][0]['path'], true)
            && ($routes[0]['handle'][0]['upstreams'][0]['dial'] ?? null) === '127.0.0.1:5173'
            && ($routes[1]['match'][0]['header']['Sec-WebSocket-Protocol'] ?? null) === ['vite-hmr']
            && ($routes[1]['handle'][0]['upstreams'][0]['dial'] ?? null) === '127.0.0.1:5173'
            && ($routes[2]['handle'][0]['upstreams'][0]['dial'] ?? null) === '127.0.0.1:9100';
    });
});
