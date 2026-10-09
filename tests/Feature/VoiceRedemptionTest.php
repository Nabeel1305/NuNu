<?php

namespace Tests\Feature;

use App\Models\PaymentCode;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditLogService;
use App\Services\Codes\CodeService;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class VoiceRedemptionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const NUMBER = '+2347000000001';

    public function test_a_call_with_the_wrong_token_is_refused_before_anything_else(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeVoiceNumber(self::NUMBER, $tenant);

        $this->dial(self::NUMBER, 'wrong-token', '123456789012#')->assertForbidden();
        $this->dial('+2340000000000', 'whatever', '123456789012#')->assertForbidden();
    }

    public function test_a_call_with_no_digits_is_asked_for_them(): void
    {
        [$tenant] = $this->makeTenant();
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);

        $this->post('/api/voice/africastalking?token=' . $token, ['destinationNumber' => self::NUMBER, 'callerNumber' => '+2348012345678'])
            ->assertOk()
            ->assertSee('GetDigits', false);
    }

    public function test_a_valid_code_settles_and_the_tenant_gets_a_signed_webhook(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);

        $secret = $this->withToken($key)->postJson('/api/v1/webhook-endpoints', ['url' => 'https://hooks.example.test/in'])
            ->assertCreated()->json('secret');

        $code = $this->issueCode($key)->json('code');

        $this->dial(self::NUMBER, $token, $code . '#')->assertOk()->assertSee('Thank you', false);

        $stored = PaymentCode::withoutGlobalScopes()->first();
        $this->assertSame('settled', $stored->state->value);
        $this->assertSame('settled', Transaction::withoutGlobalScopes()->first()->status);

        $this->assertSame(['code.redeemed', 'transaction.settled'], WebhookDelivery::withoutGlobalScopes()->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(['delivered', 'delivered'], WebhookDelivery::withoutGlobalScopes()->pluck('status')->all());

        Http::assertSent(function ($request) use ($secret) {
            return (new WebhookSigner())->verify($secret, $request->header('Offline-Signature')[0], $request->body());
        });
    }

    public function test_a_code_works_only_once(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $code = $this->issueCode($key)->json('code');

        $this->dial(self::NUMBER, $token, $code . '#')->assertOk();
        $this->dial(self::NUMBER, $token, $code . '#')->assertOk();

        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    public function test_a_wrong_code_sounds_the_same_as_a_right_one(): void
    {
        [$tenant] = $this->makeTenant();
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);

        $good = $this->dial(self::NUMBER, $token, '000000000000#')->getContent();

        $this->assertStringContainsString('Thank you', $good);
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
    }

    public function test_a_declined_capture_fails_the_transaction_and_tells_the_tenant(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        [$tenant, $key] = $this->makeTenant(['settings' => ['sandbox' => ['fail_capture' => true]]]);
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $this->withToken($key)->postJson('/api/v1/webhook-endpoints', ['url' => 'https://hooks.example.test/in']);

        $code = $this->issueCode($key)->json('code');
        $this->dial(self::NUMBER, $token, $code . '#')->assertOk();

        $this->assertSame('failed', PaymentCode::withoutGlobalScopes()->first()->state->value);
        $this->assertContains('transaction.failed', WebhookDelivery::withoutGlobalScopes()->pluck('event_type')->all());
    }

    public function test_caller_binding_rejects_a_different_number(): void
    {
        [$tenant, $key] = $this->makeTenant(['bind_caller' => true]);
        $this->seedParties($tenant, '+2348012345678');
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $code = $this->issueCode($key)->json('code');

        $this->dial(self::NUMBER, $token, $code . '#', '+2348099999999')->assertOk();
        $this->assertSame('issued', PaymentCode::withoutGlobalScopes()->first()->state->value);

        $this->dial(self::NUMBER, $token, $code . '#', '08012345678')->assertOk();
        $this->assertSame('settled', PaymentCode::withoutGlobalScopes()->first()->state->value);
    }

    public function test_repeated_wrong_guesses_from_one_caller_are_cut_off(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $code = $this->issueCode($key)->json('code');

        foreach (range(1, 5) as $i) {
            $this->dial(self::NUMBER, $token, '99999999999' . $i . '#', '+2348055555555');
        }

        // The right code from the same caller is now ignored.
        $this->dial(self::NUMBER, $token, $code . '#', '+2348055555555')->assertOk();
        $this->assertSame('issued', PaymentCode::withoutGlobalScopes()->first()->state->value);
    }

    public function test_the_shared_number_routes_by_the_tenant_prefix(): void
    {
        [$a, $keyA] = $this->makeTenant(['voice_mode' => 'shared']);
        [$b, $keyB] = $this->makeTenant(['voice_mode' => 'shared']);
        $this->seedParties($a);
        $this->seedParties($b);
        $token = $this->makeVoiceNumber(self::NUMBER, null);

        $codeA = $this->issueCode($keyA)->json('code');
        $codeB = $this->issueCode($keyB)->json('code');

        $this->assertStringStartsWith($a->short_code, $codeA);
        $this->assertStringStartsWith($b->short_code, $codeB);

        $this->dial(self::NUMBER, $token, $codeA . '#')->assertOk();

        $states = PaymentCode::withoutGlobalScopes()->orderBy('id')->get()->pluck('state.value', 'tenant_id');
        $this->assertSame('settled', $states[$a->id]);
        $this->assertSame('issued', $states[$b->id]);
    }

    public function test_due_codes_expire_and_cannot_be_redeemed(): void
    {
        [$tenant, $key] = $this->makeTenant(['code_ttl_minutes' => 1]);
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $code = $this->issueCode($key)->json('code');

        $this->travel(2)->minutes();

        $this->assertSame(1, app(CodeService::class)->expireDue());
        $this->dial(self::NUMBER, $token, $code . '#')->assertOk();

        $this->assertSame('expired', PaymentCode::withoutGlobalScopes()->first()->state->value);
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
    }

    public function test_the_audit_chain_is_intact_and_detects_tampering(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $code = $this->issueCode($key)->json('code');
        $this->dial(self::NUMBER, $token, $code . '#');

        $audit = app(AuditLogService::class);
        $this->assertSame([], $audit->verifyChain($tenant->id));

        $first = DB::table('audit_logs')->where('tenant_id', $tenant->id)->orderBy('id')->first();
        DB::table('audit_logs')->where('id', $first->id)->update(['description' => 'tampered']);

        $this->assertContains($first->id, $audit->verifyChain($tenant->id));
    }

    public function test_repeated_bad_tokens_from_one_address_are_cut_off(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeVoiceNumber(self::NUMBER, $tenant);

        foreach (range(1, 30) as $i) {
            $this->dial(self::NUMBER, 'wrong', '123456789012#')->assertForbidden();
        }

        $this->dial(self::NUMBER, 'wrong', '123456789012#')->assertStatus(429);
    }

    public function test_good_traffic_is_not_throttled_by_bad_tokens_from_elsewhere(): void
    {
        [$tenant] = $this->makeTenant();
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);

        foreach (range(1, 30) as $i) {
            $this->dial(self::NUMBER, 'wrong', '123456789012#');
        }

        // A different source address with the right token still gets through.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->dial(self::NUMBER, $token, '123456789012#')->assertOk();
    }

    public function test_rotating_a_token_cuts_off_the_old_url(): void
    {
        [$tenant] = $this->makeTenant();
        $old = $this->makeVoiceNumber(self::NUMBER, $tenant);
        $number = \App\Models\VoiceNumber::first();

        $admin = \App\Models\Admin::create(['name' => 'Ops', 'email' => 'o@example.test', 'password' => 'a-long-test-password']);
        $this->actingAs($admin, 'admin')->post("/admin/tenants/{$tenant->id}/numbers/{$number->id}/rotate-token")->assertRedirect();

        $this->dial(self::NUMBER, $old, '123456789012#')->assertForbidden();
    }
}
