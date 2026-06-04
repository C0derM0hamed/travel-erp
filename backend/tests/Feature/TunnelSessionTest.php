<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureStatefulApiRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TunnelSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_session_persists_for_cloudflare_tunnel_host(): void
    {
        $tunnelHost = 'partnership-chem-handhelds-undefined.trycloudflare.com';
        $password = env('SEED_USER_PASSWORD', 'travel-erp-test-secret');

        $server = [
            'HTTP_HOST' => $tunnelHost,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => $tunnelHost,
            'HTTPS' => 'on',
        ];

        $this->withServerVariables($server)
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent();

        $this->withServerVariables($server)
            ->postJson('/api/login', [
                'email' => 'admin@travel.kw',
                'password' => $password,
            ], [
                'Origin' => "https://{$tunnelHost}",
                'Referer' => "https://{$tunnelHost}/",
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@travel.kw');

        $this->withServerVariables($server)
            ->getJson('/api/bootstrap', [
                'Origin' => "https://{$tunnelHost}",
                'Referer' => "https://{$tunnelHost}/",
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['user', 'users', 'services', 'safes', 'metrics']);

        $this->withServerVariables($server)
            ->getJson('/api/me', [
                'Origin' => "https://{$tunnelHost}",
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@travel.kw');
    }

    public function test_stateful_domains_normalize_https_entries_from_env(): void
    {
        config(['sanctum.stateful' => [
            'https://tunnel.example.trycloudflare.com/',
            '__SANCTUM_CURRENT_REQUEST_HOST__',
        ]]);

        $this->assertTrue(
            EnsureStatefulApiRequests::fromFrontend(
                Request::create(
                    'https://tunnel.example.trycloudflare.com/api/bootstrap',
                    'GET',
                    [],
                    [],
                    [],
                    [
                        'HTTP_HOST' => 'tunnel.example.trycloudflare.com',
                        'HTTP_REFERER' => 'https://tunnel.example.trycloudflare.com/',
                        'HTTPS' => 'on',
                    ]
                )
            )
        );
    }
}
