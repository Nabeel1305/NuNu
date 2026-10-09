<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_browser_preflight_to_the_api_is_answered(): void
    {
        $this->call('OPTIONS', '/api/v1/codes', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,idempotency-key',
        ])
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods');

        $allowed = strtolower($this->call('OPTIONS', '/api/v1/codes', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('idempotency-key', $allowed);
        $this->assertStringContainsString('authorization', $allowed);
    }

    public function test_real_api_responses_carry_the_origin_header_and_never_allow_credentials(): void
    {
        [, $key] = $this->makeTenant();

        $response = $this->withHeader('Origin', 'https://app.example.com')->withToken($key)->getJson('/api/v1/codes/' . \Illuminate\Support\Str::uuid());

        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_the_dashboard_pages_get_no_cors_headers(): void
    {
        $this->withHeader('Origin', 'https://evil.example.com')->get('/admin/login')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_an_allow_list_restricts_origins(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);

        $this->call('OPTIONS', '/api/v1/codes', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
