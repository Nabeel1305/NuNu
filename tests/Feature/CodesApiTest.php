<?php

namespace Tests\Feature;

use App\Models\PaymentCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CodesApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_requests_without_a_valid_key_are_refused(): void
    {
        $this->postJson('/api/v1/codes', $this->issueBody())->assertStatus(401);
        $this->withToken('opk_deadbeef_' . str_repeat('a', 40))->getJson('/api/v1/codes/x')->assertStatus(401);
    }

    public function test_issuing_returns_the_code_once_and_never_stores_it(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        $response = $this->issueCode($key)->assertCreated();
        $plain = $response->json('code');

        $this->assertMatchesRegularExpression('/^\d{12}$/', $plain);
        $this->assertSame('issued', $response->json('state'));

        $this->withToken($key)->getJson('/api/v1/codes/' . $response->json('id'))
            ->assertOk()
            ->assertJsonMissingPath('code');

        $this->assertDatabaseMissing('payment_codes', ['code_hash' => $plain]);
    }

    public function test_the_same_idempotency_key_replays_instead_of_issuing_twice(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        $first = $this->issueCode($key, [], 'retry-1')->assertCreated();
        $second = $this->issueCode($key, [], 'retry-1')->assertCreated();

        $this->assertSame($first->json('code'), $second->json('code'));
        $this->assertSame(1, PaymentCode::withoutGlobalScopes()->count());
    }

    public function test_reusing_a_key_with_a_different_body_is_refused(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        $this->issueCode($key, [], 'retry-2')->assertCreated();
        $this->issueCode($key, ['amount_minor' => 1], 'retry-2')->assertStatus(422);
    }

    public function test_a_tenant_cannot_see_another_tenants_code(): void
    {
        [$a, $keyA] = $this->makeTenant();
        [, $keyB] = $this->makeTenant();
        $this->seedParties($a);

        $id = $this->issueCode($keyA)->json('id');

        $this->withToken($keyB)->getJson("/api/v1/codes/{$id}")->assertNotFound();
        $this->withToken($keyB)->withHeader('Idempotency-Key', 'x')->postJson("/api/v1/codes/{$id}/cancel")->assertNotFound();
    }

    public function test_a_rejected_hold_creates_no_code(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['sandbox' => ['fail_hold' => true]]]);
        $this->seedParties($tenant);

        $this->issueCode($key)->assertStatus(422)->assertJsonPath('error.code', 'settlement_rejected');

        $this->assertSame(0, PaymentCode::withoutGlobalScopes()->count());
    }

    public function test_cancelling_blocks_later_redemption(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $number = '+2347000000001';
        $token = $this->makeVoiceNumber($number, $tenant);

        $issued = $this->issueCode($key)->assertCreated();

        $this->withToken($key)->withHeader('Idempotency-Key', 'cancel-1')
            ->postJson('/api/v1/codes/' . $issued->json('id') . '/cancel')
            ->assertOk()->assertJsonPath('state', 'cancelled');

        $this->dial($number, $token, $issued->json('code') . '#')->assertOk();

        $this->assertSame('cancelled', PaymentCode::withoutGlobalScopes()->first()->state->value);
    }

    public function test_an_overlimit_amount_is_refused(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['max_amount_minor' => 1000]]);
        $this->seedParties($tenant);

        $this->issueCode($key, ['amount_minor' => 1001])->assertStatus(422);
    }

    public function test_a_flood_of_bad_keys_from_one_address_is_cut_off_but_good_keys_still_work_elsewhere(): void
    {
        [, $key] = $this->makeTenant();
        $bad = 'opk_deadbeef_' . str_repeat('a', 40);

        foreach (range(1, 30) as $i) {
            $this->withToken($bad)->getJson('/api/v1/codes/x')->assertStatus(401);
        }

        $this->withToken($bad)->getJson('/api/v1/codes/x')->assertStatus(429);
        // Even this address's good key is refused while it is cut off; another address is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withToken($key)->getJson('/api/v1/codes/x')->assertNotFound();
    }
}
