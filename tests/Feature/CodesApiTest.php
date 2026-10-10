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

    public function test_the_response_carries_the_number_and_a_ready_to_dial_string(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->makeVoiceNumber('+2347000000001', $tenant);

        $r = $this->issueCode($key)->assertCreated();
        $code = $r->json('code');

        $r->assertJsonPath('voice_number', '+2347000000001')
            ->assertJsonPath('dial_string', "+2347000000001,,,{$code}#")
            ->assertJsonPath('dial_uri', "tel:+2347000000001,,,{$code}%23");
    }

    public function test_a_number_stored_without_a_plus_is_still_dialled_in_international_form(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->makeVoiceNumber('2347000000009', $tenant);

        $this->issueCode($key)->assertJsonPath('voice_number', '+2347000000009');
    }

    public function test_a_shared_pool_tenant_gets_the_pool_number_and_its_prefixed_code(): void
    {
        [$tenant, $key] = $this->makeTenant(['voice_mode' => 'shared']);
        $this->seedParties($tenant);
        $this->makeVoiceNumber('+2347000000099');                  // shared pool
        [$other] = $this->makeTenant();
        $this->makeVoiceNumber('+2347000000002', $other);          // someone else's own number

        $r = $this->issueCode($key)->assertCreated();

        $this->assertStringStartsWith($tenant->short_code, $r->json('code'));
        $r->assertJsonPath('voice_number', '+2347000000099')
            ->assertJsonPath('dial_string', '+2347000000099,,,' . $r->json('code') . '#');
    }

    public function test_a_tenant_never_gets_another_tenants_number_or_a_disabled_one(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        [$other] = $this->makeTenant();
        $this->makeVoiceNumber('+2347000000002', $other);
        $this->makeVoiceNumber('+2347000000003', $tenant);
        \App\Models\VoiceNumber::where('number', '+2347000000003')->update(['active' => false]);

        $this->issueCode($key)->assertCreated()
            ->assertJsonPath('voice_number', null)
            ->assertJsonPath('dial_string', null)
            ->assertJsonPath('dial_uri', null);
    }

    public function test_the_dial_string_comes_back_unchanged_on_an_idempotent_replay(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->makeVoiceNumber('+2347000000001', $tenant);

        $first = $this->issueCode($key, [], 'same-key')->assertCreated();
        $again = $this->issueCode($key, [], 'same-key');

        $this->assertSame($first->json('dial_string'), $again->json('dial_string'));
        $this->assertSame($first->json('code'), $again->json('code'));
    }

    public function test_reading_a_code_never_returns_the_digits_or_the_dial_string(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->makeVoiceNumber('+2347000000001', $tenant);
        $id = $this->issueCode($key)->json('id');

        $this->withToken($key)->getJson("/api/v1/codes/{$id}")->assertOk()
            ->assertJsonMissingPath('code')->assertJsonMissingPath('dial_string')->assertJsonMissingPath('dial_uri');
    }

    // ── merchants registered on the fly ───────────────────────────────────────

    private function onlySubscriber($tenant): void
    {
        app(\App\Support\TenantContext::class)->run($tenant, fn () => \App\Models\Subscriber::create(['reference' => 'sub-1', 'phone' => '+2348012345678', 'account_number' => '2000000001', 'bank_code' => '058']));
    }

    private function merchants($tenant)
    {
        return \App\Models\Merchant::withoutGlobalScopes()->where('tenant_id', $tenant->id);
    }

    public function test_an_unknown_merchant_is_created_while_the_code_is_issued(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->onlySubscriber($tenant);

        $r = $this->issueCode($key, ['merchant_reference' => 'shop-new', 'merchant' => ['name' => 'Fresh Shop', 'account_number' => '3000000777', 'bank_code' => '011']])->assertCreated();

        $r->assertJsonPath('merchant_created', true);
        $m = $this->merchants($tenant)->where('reference', 'shop-new')->first();
        $this->assertSame('Fresh Shop', $m->name);
        $this->assertSame('3000000777', $m->account_number);
        $this->assertSame('011', $m->bank_code);
        $this->assertSame(1, \App\Models\AuditLog::where('tenant_id', $tenant->id)->where('event_type', 'merchant.created')->count());
        $this->assertSame([], app(\App\Services\Audit\AuditLogService::class)->verifyChain($tenant->id));
    }

    public function test_an_unknown_merchant_without_details_is_still_a_404(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->onlySubscriber($tenant);

        $this->issueCode($key, ['merchant_reference' => 'ghost'])->assertNotFound();
        $this->assertSame(0, $this->merchants($tenant)->count());
    }

    public function test_an_existing_merchant_is_used_as_is_and_never_rewritten(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);                                  // shop-1 / Corner Shop / acct-shop

        $r = $this->issueCode($key, ['merchant' => ['name' => 'A Different Name', 'account_number' => '3000000001', 'bank_code' => '011']])->assertCreated();

        $r->assertJsonPath('merchant_created', false);
        $this->assertSame('Corner Shop', $this->merchants($tenant)->where('reference', 'shop-1')->value('name'));
        $this->assertSame(1, $this->merchants($tenant)->count());
    }

    public function test_a_payment_request_cannot_redirect_an_existing_merchants_account(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        $this->issueCode($key, ['merchant' => ['name' => 'Corner Shop', 'account_number' => '3999999999', 'bank_code' => '011']])
            ->assertStatus(422)->assertJsonValidationErrors('merchant.account_number');
        $this->issueCode($key, ['merchant' => ['name' => 'Corner Shop', 'account_number' => '3000000001', 'bank_code' => '999']])
            ->assertStatus(422)->assertJsonValidationErrors('merchant.account_number');

        $this->assertSame('3000000001', $this->merchants($tenant)->where('reference', 'shop-1')->value('account_number'));
        $this->assertSame(0, PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_merchant_is_not_created_when_the_hold_is_refused(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['sandbox' => ['fail_hold' => true]]]);
        $this->onlySubscriber($tenant);

        $this->issueCode($key, ['merchant_reference' => 'shop-new', 'merchant' => ['name' => 'Fresh Shop', 'account_number' => '3000000777', 'bank_code' => '011']])->assertStatus(422);

        $this->assertSame(0, $this->merchants($tenant)->count());
    }

    public function test_incomplete_merchant_details_are_a_validation_error(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->onlySubscriber($tenant);

        $this->issueCode($key, ['merchant_reference' => 'x', 'merchant' => ['name' => 'No account', 'bank_code' => '011']])->assertStatus(422)->assertJsonValidationErrors('merchant.account_number');
        $this->issueCode($key, ['merchant_reference' => 'x', 'merchant' => ['name' => 'No bank', 'account_number' => '3000000777']])->assertStatus(422)->assertJsonValidationErrors('merchant.bank_code');
        $this->issueCode($key, ['merchant_reference' => 'x', 'merchant' => ['account_number' => '3000000777', 'bank_code' => '011']])->assertStatus(422)->assertJsonValidationErrors('merchant.name');
        $this->issueCode($key, ['merchant_reference' => 'x', 'merchant' => ['name' => 'Bad', 'account_number' => '12345', 'bank_code' => '011']])->assertStatus(422)->assertJsonValidationErrors('merchant.account_number');
        $this->assertSame(0, $this->merchants($tenant)->count());
    }

    public function test_a_replayed_request_does_not_create_the_merchant_twice(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->onlySubscriber($tenant);
        $body = ['merchant_reference' => 'shop-new', 'merchant' => ['name' => 'Fresh Shop', 'account_number' => '3000000777', 'bank_code' => '011']];

        $first = $this->issueCode($key, $body, 'k-merchant')->assertCreated();
        $again = $this->issueCode($key, $body, 'k-merchant');

        $this->assertSame($first->json('code'), $again->json('code'));
        $this->assertSame(1, $this->merchants($tenant)->count());
    }

    public function test_two_tenants_can_each_create_a_merchant_with_the_same_reference(): void
    {
        [$a, $keyA] = $this->makeTenant();
        [$b, $keyB] = $this->makeTenant();
        $this->onlySubscriber($a);
        $this->onlySubscriber($b);
        $details = fn ($acct) => ['merchant_reference' => 'shop-new', 'merchant' => ['name' => 'Shop', 'account_number' => $acct, 'bank_code' => '011']];

        $this->issueCode($keyA, $details('3000000011'))->assertCreated();
        $this->issueCode($keyB, $details('3000000022'))->assertCreated();

        $this->assertSame('3000000011', $this->merchants($a)->value('account_number'));
        $this->assertSame('3000000022', $this->merchants($b)->value('account_number'));
    }

    public function test_a_second_code_for_the_merchant_made_on_the_fly_uses_it_without_details(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->onlySubscriber($tenant);
        $this->issueCode($key, ['merchant_reference' => 'shop-new', 'merchant' => ['name' => 'Fresh Shop', 'account_number' => '3000000777', 'bank_code' => '011']])->assertCreated();

        $this->issueCode($key, ['merchant_reference' => 'shop-new'])->assertCreated()->assertJsonPath('merchant_created', false);
    }

    // ── bank accounts are required ────────────────────────────────────────────

    public function test_a_subscriber_must_be_registered_with_an_account_number_and_bank_code(): void
    {
        [, $key] = $this->makeTenant();

        $this->withToken($key)->putJson('/api/v1/subscribers/cust-1', ['phone' => '+2348012345678'])
            ->assertStatus(422)->assertJsonValidationErrors(['account_number', 'bank_code']);
        $this->withToken($key)->putJson('/api/v1/subscribers/cust-1', ['account_number' => '2000000001'])->assertStatus(422)->assertJsonValidationErrors('bank_code');
        $this->withToken($key)->putJson('/api/v1/subscribers/cust-1', ['account_number' => '2000000001', 'bank_code' => '058'])
            ->assertCreated()->assertJsonPath('account_number', '2000000001')->assertJsonPath('bank_code', '058');
    }

    public function test_account_numbers_and_bank_codes_must_look_right(): void
    {
        [, $key] = $this->makeTenant();

        foreach (['123', 'abcdefghij', '20000000011', '2000 00001'] as $bad) {
            $this->withToken($key)->putJson('/api/v1/subscribers/c', ['account_number' => $bad, 'bank_code' => '058'])->assertStatus(422)->assertJsonValidationErrors('account_number');
        }
        foreach (['', '05', '058-1', 'toolongbankcode'] as $bad) {
            $this->withToken($key)->putJson('/api/v1/subscribers/c', ['account_number' => '2000000001', 'bank_code' => $bad])->assertStatus(422)->assertJsonValidationErrors('bank_code');
        }
        $this->withToken($key)->putJson('/api/v1/subscribers/c', ['account_number' => '2000000001', 'bank_code' => '999992'])->assertCreated();   // fintech-style code
    }

    public function test_a_merchant_must_be_registered_with_an_account_number_and_bank_code(): void
    {
        [, $key] = $this->makeTenant();

        $this->withToken($key)->putJson('/api/v1/merchants/shop-1', ['name' => 'Corner Shop', 'account_reference' => 'acct-shop'])
            ->assertStatus(422)->assertJsonValidationErrors(['account_number', 'bank_code']);
        $this->withToken($key)->putJson('/api/v1/merchants/shop-1', ['name' => 'Corner Shop', 'account_number' => '3000000001', 'bank_code' => '011'])
            ->assertCreated()->assertJsonPath('account_number', '3000000001')->assertJsonPath('account_reference', null);
    }

    public function test_the_hold_is_placed_on_the_subscribers_account_and_the_capture_goes_to_the_merchants(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $seen = new \ArrayObject();
        $this->app->bind(\App\Services\Settlement\SandboxAdapter::class, fn () => new class($seen) extends \App\Services\Settlement\SandboxAdapter {
            public function __construct(private \ArrayObject $seen) {}
            public function hold(\App\Models\Tenant $t, string $r, \App\Services\Settlement\AccountRef $source, int $a, string $c): \App\Services\Settlement\SettlementResult { $this->seen['hold'] = $source; return parent::hold($t, $r, $source, $a, $c); }
            public function capture(\App\Models\Tenant $t, string $h, \App\Services\Settlement\AccountRef $destination, string $x): \App\Services\Settlement\SettlementResult { $this->seen['capture'] = $destination; return parent::capture($t, $h, $destination, $x); }
        });
        $token = $this->makeVoiceNumber('+2347000000001', $tenant);

        $r = $this->issueCode($key, ['source_account_reference' => 'my-label'])->assertCreated();
        $this->assertSame(['2000000001', '058', 'my-label'], [$seen['hold']->number, $seen['hold']->bankCode, $seen['hold']->reference]);

        // The merchant is then edited; the code in flight must still pay the original account.
        $this->withToken($key)->putJson('/api/v1/merchants/shop-1', ['name' => 'Corner Shop', 'account_number' => '3999999999', 'bank_code' => '044'])->assertOk();
        $this->dial('+2347000000001', $token, $r->json('code') . '#')->assertOk();

        $this->assertSame(['3000000001', '011', 'acct-shop'], [$seen['capture']->number, $seen['capture']->bankCode, $seen['capture']->reference]);
    }

    public function test_the_source_account_reference_is_optional_and_defaults_to_the_subscribers_account_number(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        $body = $this->issueBody();
        unset($body['source_account_reference']);
        $id = $this->withToken($key)->withHeader('Idempotency-Key', 'k-src')->postJson('/api/v1/codes', $body)->assertCreated()->json('id');

        $code = PaymentCode::withoutGlobalScopes()->where('uuid', $id)->first();
        $this->assertSame('2000000001', $code->source_account_reference);
        $this->assertSame(['2000000001', '058', '3000000001', '011'], [$code->source_account_number, $code->source_bank_code, $code->destination_account_number, $code->destination_bank_code]);
    }

    public function test_a_subscriber_or_merchant_without_a_bank_account_on_file_must_be_updated_first(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        // Rows from before accounts were required.
        \App\Models\Subscriber::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['account_number' => null, 'bank_code' => null]);
        $this->issueCode($key)->assertStatus(422)->assertJsonValidationErrors('subscriber_reference');

        $this->withToken($key)->putJson('/api/v1/subscribers/sub-1', ['phone' => '+2348012345678', 'account_number' => '2000000001', 'bank_code' => '058'])->assertOk();
        $this->merchants($tenant)->update(['account_number' => null, 'bank_code' => null]);
        $this->issueCode($key)->assertStatus(422)->assertJsonValidationErrors('merchant_reference');

        $this->withToken($key)->putJson('/api/v1/merchants/shop-1', ['name' => 'Corner Shop', 'account_number' => '3000000001', 'bank_code' => '011'])->assertOk();
        $this->issueCode($key)->assertCreated();
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
