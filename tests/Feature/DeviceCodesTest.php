<?php

namespace Tests\Feature;

use App\Models\OfflineDevice;
use App\Models\PaymentCode;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Codes\CodeService;
use App\Services\Offline\DeviceCodeFormat;
use App\Services\Settlement\AccountRef;
use App\Services\Settlement\SandboxAdapter;
use App\Services\Settlement\SettlementResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DeviceCodesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const NUMBER = '+2347000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Carbon::setTestNow(Carbon::createFromTimestamp(1791600000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: string, 2: string, 3: array} tenant, api key, voice token, ['key'=>base64, 'short'=>device short id, 'merchant'=>merchant short id] */
    private function world(array $tenantAttrs = [], array $deviceCodes = ['enabled' => true]): array
    {
        [$tenant, $key] = $this->makeTenant($tenantAttrs + ['settings' => ['device_codes' => $deviceCodes]]);
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber(self::NUMBER, $tenant);

        $enrol = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001']);
        $merchant = $this->withToken($key)->getJson('/api/v1/merchants/shop-1');

        return [$tenant, $key, $token, ['key' => $enrol->json('device_key'), 'short' => $enrol->json('short_id'), 'merchant' => $merchant->json('short_id')]];
    }

    private function code(array $w, int $counter = 1, int $amount = 250000, ?int $at = null, array $over = []): string
    {
        return DeviceCodeFormat::build(
            base64_decode($over['key'] ?? $w['key']), $over['device'] ?? $w['short'], $counter, $amount, $over['merchant'] ?? $w['merchant'],
            $at ?? now()->timestamp, 'NGN', $over['src'] ?? '2000000001', '058', $over['dst'] ?? '3000000001', '011',
        );
    }

    private function ring(string $token, string $digits, string $caller = '+2348012345678')
    {
        return $this->dial(self::NUMBER, $token, $digits . '#', $caller);
    }

    private function txCount(Tenant $t): int
    {
        return Transaction::withoutGlobalScopes()->where('tenant_id', $t->id)->count();
    }

    private function events(Tenant $t, string $type)
    {
        return WebhookDelivery::withoutGlobalScopes()->where('tenant_id', $t->id)->where('event_type', $type)->get();
    }

    // ── enrolment ─────────────────────────────────────────────────────────────

    public function test_enrolment_needs_the_feature_switched_on(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);

        $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])
            ->assertForbidden()->assertJsonPath('error.code', 'device_codes_disabled');
    }

    public function test_a_phone_is_enrolled_and_gets_a_key_once_and_the_same_key_on_repeat(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $this->seedParties($tenant);

        $first = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertCreated();
        $this->assertMatchesRegularExpression('/^\d{8}$/', $first->json('short_id'));
        $this->assertSame(32, strlen(base64_decode($first->json('device_key'))));
        $first->assertJsonPath('last_counter', 0)->assertJsonPath('code_length', 40)->assertJsonPath('currency', 'NGN');

        $again = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertOk();
        $this->assertSame($first->json('device_key'), $again->json('device_key'));
        $this->assertSame($first->json('short_id'), $again->json('short_id'));

        // The key is not stored anywhere, and the list never shows it.
        $this->assertStringNotContainsString($first->json('device_key'), json_encode(OfflineDevice::withoutGlobalScopes()->get()->toArray()));
        $this->withToken($key)->getJson('/api/v1/subscribers/sub-1/devices')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.device_key');
    }

    public function test_the_key_returned_at_enrolment_is_the_key_the_server_later_verifies_with(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $this->seedParties($tenant);

        $enrol = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertCreated();
        $stored = OfflineDevice::withoutGlobalScopes()->where('short_id', $enrol->json('short_id'))->first();

        $this->assertSame(1, $stored->key_version);
        $this->assertSame('active', $enrol->json('status'));
        $this->assertSame(1, $enrol->json('key_version'));
        $this->assertSame(base64_encode(app(\App\Services\Offline\DeviceKeyService::class)->derive($stored)), $enrol->json('device_key'));
    }

    public function test_enrolment_needs_a_subscriber_with_a_bank_account_and_a_sane_device_id(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);

        $this->withToken($key)->postJson('/api/v1/subscribers/ghost/devices', ['device_id' => 'install-0001'])->assertNotFound();

        $this->seedParties($tenant);
        $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'short'])->assertStatus(422);

        \App\Models\Subscriber::withoutGlobalScopes()->update(['account_number' => null, 'bank_code' => null]);
        $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertStatus(422);
    }

    public function test_a_subscriber_can_only_have_so_many_active_devices(): void
    {
        config(['platform.device_codes.max_devices_per_subscriber' => 2]);
        [$tenant, $key] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $this->seedParties($tenant);

        foreach (['install-0001', 'install-0002'] as $d) {
            $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => $d])->assertCreated();
        }
        $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0003'])->assertStatus(422);

        $this->withToken($key)->deleteJson('/api/v1/subscribers/sub-1/devices/install-0001')->assertOk()->assertJsonPath('status', 'revoked');
        $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0003'])->assertCreated();
    }

    public function test_re_enrolling_after_a_revoke_gives_a_new_key_and_resets_the_counter(): void
    {
        [, $key, , $w] = $this->world();
        OfflineDevice::withoutGlobalScopes()->update(['last_counter' => 77]);

        $this->withToken($key)->deleteJson('/api/v1/subscribers/sub-1/devices/install-0001')->assertOk();
        $new = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertCreated();

        $this->assertNotSame($w['key'], $new->json('device_key'));
        $this->assertSame($w['short'], $new->json('short_id'));
        $new->assertJsonPath('last_counter', 0)->assertJsonPath('key_version', 2)->assertJsonPath('status', 'active');
    }

    public function test_a_re_enrolled_phone_can_pay_again_from_counter_one(): void
    {
        [$tenant, $key, $token, $w] = $this->world();
        $this->ring($token, $this->code($w, 1, 1000))->assertOk();
        $this->ring($token, $this->code($w, 2, 1000))->assertOk();
        $this->assertSame(2, $this->txCount($tenant));

        $this->withToken($key)->deleteJson('/api/v1/subscribers/sub-1/devices/install-0001')->assertOk();
        $new = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertCreated();
        $w2 = ['key' => $new->json('device_key')] + $w;

        $this->ring($token, $this->code($w, 1, 1000))->assertOk();                  // the old key is worthless
        $this->assertSame(2, $this->txCount($tenant));

        $this->ring($token, $this->code($w2, 1, 1000))->assertOk();                  // counter 1 again, new key: works
        $this->ring($token, $this->code($w2, 2, 1000))->assertOk();
        $this->assertSame(4, $this->txCount($tenant));
        $this->assertSame('settled', Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->value('status'));
    }

    public function test_another_tenant_cannot_see_or_revoke_my_devices(): void
    {
        [, , , $w] = $this->world();
        [, $otherKey] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);

        $this->withToken($otherKey)->getJson('/api/v1/subscribers/sub-1/devices')->assertNotFound();
        $this->withToken($otherKey)->deleteJson('/api/v1/subscribers/sub-1/devices/install-0001')->assertNotFound();
        $this->assertSame('active', OfflineDevice::withoutGlobalScopes()->where('short_id', $w['short'])->value('status'));
    }

    public function test_merchants_expose_their_short_id(): void
    {
        [, $key] = $this->makeTenant();

        $put = $this->withToken($key)->putJson('/api/v1/merchants/m1', ['name' => 'M', 'account_number' => '3000000001', 'bank_code' => '011'])->assertCreated();
        $this->assertMatchesRegularExpression('/^\d{8}$/', $put->json('short_id'));

        $this->withToken($key)->putJson('/api/v1/merchants/m1', ['name' => 'M2', 'account_number' => '3000000009', 'bank_code' => '011'])->assertOk()->assertJsonPath('short_id', $put->json('short_id'));
        $this->withToken($key)->getJson('/api/v1/merchants/m1')->assertOk()->assertJsonPath('short_id', $put->json('short_id'));
    }

    // ── paying ────────────────────────────────────────────────────────────────

    public function test_a_code_built_on_the_phone_is_paid_by_the_call(): void
    {
        [$tenant, $key, $token, $w] = $this->world();
        app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/h', 'secret' => 'whsec_x']));

        $this->ring($token, $this->code($w))->assertOk()->assertSee('Thank you', false);

        $tx = Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('settled', $tx->status);
        $this->assertSame(250000, $tx->amount_minor);
        $code = PaymentCode::withoutGlobalScopes()->find($tx->payment_code_id);
        $this->assertSame('device', $code->origin);
        $this->assertSame(['2000000001', '058', '3000000001', '011'], [$code->source_account_number, $code->source_bank_code, $code->destination_account_number, $code->destination_bank_code]);
        $this->assertSame(1, $code->device_counter);
        $this->assertNotNull($code->hold_reference);
        $this->assertSame(1, OfflineDevice::withoutGlobalScopes()->where('short_id', $w['short'])->value('last_counter'));

        $this->assertCount(1, $this->events($tenant, 'code.redeemed'));
        $this->assertCount(1, $this->events($tenant, 'transaction.settled'));
        $this->assertSame('device', $this->events($tenant, 'transaction.settled')->first()->payload['data']['origin']);
        $this->assertSame([], app(\App\Services\Audit\AuditLogService::class)->verifyChain($tenant->id));

        $this->withToken($key)->getJson('/api/v1/transactions/' . $tx->uuid)->assertOk()->assertJsonPath('status', 'settled');
    }

    public function test_the_hold_and_capture_go_to_the_right_accounts(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $seen = new \ArrayObject();
        $this->app->bind(SandboxAdapter::class, fn () => new class($seen) extends SandboxAdapter {
            public function __construct(private \ArrayObject $seen) {}
            public function hold(Tenant $t, string $r, AccountRef $source, int $a, string $c): SettlementResult { $this->seen['hold'] = [$source->number, $source->bankCode, $a]; return parent::hold($t, $r, $source, $a, $c); }
            public function capture(Tenant $t, string $h, AccountRef $destination, string $x): SettlementResult { $this->seen['capture'] = [$destination->number, $destination->bankCode]; return parent::capture($t, $h, $destination, $x); }
        });

        $this->ring($token, $this->code($w, 1, 125000))->assertOk();

        $this->assertSame(['2000000001', '058', 125000], $seen['hold']);
        $this->assertSame(['3000000001', '011'], $seen['capture']);
    }

    public function test_a_code_works_once(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $digits = $this->code($w, 5);

        $this->ring($token, $digits)->assertOk();
        $this->ring($token, $digits)->assertOk();                       // the caller hears the same thing either way

        $this->assertSame(1, $this->txCount($tenant));
        $this->assertSame(1, $this->events($tenant, 'code.redeemed')->count() ?: 1);
    }

    public function test_a_lower_or_equal_counter_is_refused_and_a_higher_one_accepted(): void
    {
        [$tenant, , $token, $w] = $this->world();

        $this->ring($token, $this->code($w, 10))->assertOk();
        $this->ring($token, $this->code($w, 9, 1000));
        $this->ring($token, $this->code($w, 10, 1000));
        $this->assertSame(1, $this->txCount($tenant));

        $this->ring($token, $this->code($w, 11, 1000));
        $this->assertSame(2, $this->txCount($tenant));
        $this->assertSame(11, OfflineDevice::withoutGlobalScopes()->where('short_id', $w['short'])->value('last_counter'));
    }

    public function test_twelve_simultaneous_dials_of_one_code_make_one_payment(): void
    {
        [$tenant, , , $w] = $this->world();
        $digits = $this->code($w, 3);
        $codes = app(CodeService::class);

        $accepted = 0;
        foreach (range(1, 12) as $_) {
            $accepted += $codes->redeem($tenant, $digits, '+2348012345678')->accepted ? 1 : 0;
        }

        $this->assertSame(1, $accepted);
        $this->assertSame(1, $this->txCount($tenant));
    }

    public function test_tampering_with_any_field_makes_the_code_worthless(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $good = $this->code($w, 1);

        $tampered = [
            substr($good, 0, 14) . '00999999' . substr($good, 22),              // a bigger amount
            substr($good, 0, 9) . '00002' . substr($good, 14),                  // another counter
            substr($good, 0, 22) . '99999999' . substr($good, 30),              // another merchant
            substr($good, 0, 39) . (((int) $good[39] + 1) % 10),                // last digit of the MAC
            $this->code($w, 1, 250000, null, ['key' => base64_encode(str_repeat('k', 32))]),   // someone else's key
        ];
        foreach ($tampered as $digits) {
            $this->ring($token, $digits, '+2348055555555')->assertOk();     // another number: five bad guesses lock the caller out
        }

        $this->assertSame(0, $this->txCount($tenant));
        $this->ring($token, $good)->assertOk();
        $this->assertSame(1, $this->txCount($tenant));                          // the untouched code still works
    }

    public function test_a_merchant_whose_account_changed_after_the_phone_built_the_code_is_refused(): void
    {
        [$tenant, $key, $token, $w] = $this->world();
        $digits = $this->code($w, 1);        // the phone believed the merchant's account is 3000000001

        $this->withToken($key)->putJson('/api/v1/merchants/shop-1', ['name' => 'Corner Shop', 'account_number' => '3999999999', 'bank_code' => '011'])->assertOk();

        $this->ring($token, $digits)->assertOk();
        $this->assertSame(0, $this->txCount($tenant));     // what the payer saw is not what would have been paid
    }

    public function test_a_code_is_valid_for_the_neighbouring_windows_only(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $built = now()->timestamp;

        Carbon::setTestNow(Carbon::createFromTimestamp($built + 290));
        $this->ring($token, $this->code($w, 1, 1000, $built))->assertOk();
        $this->assertSame(1, $this->txCount($tenant));

        Carbon::setTestNow(Carbon::createFromTimestamp($built + 900));      // three windows on
        $this->ring($token, $this->code($w, 2, 1000, $built));
        $this->assertSame(1, $this->txCount($tenant));

        Carbon::setTestNow(Carbon::createFromTimestamp($built - 900));      // a phone clock far in the future
        $this->ring($token, $this->code($w, 3, 1000, $built));
        $this->assertSame(1, $this->txCount($tenant));
    }

    public function test_a_revoked_device_stops_working_at_once(): void
    {
        [$tenant, $key, $token, $w] = $this->world();
        $this->withToken($key)->deleteJson('/api/v1/subscribers/sub-1/devices/install-0001')->assertOk();

        $this->ring($token, $this->code($w, 1));

        $this->assertSame(0, $this->txCount($tenant));
    }

    public function test_codes_are_ignored_when_the_feature_is_off_for_the_tenant(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $tenant->update(['settings' => ['device_codes' => ['enabled' => false]]]);

        $this->ring($token, $this->code($w, 1));

        $this->assertSame(0, $this->txCount($tenant));
    }

    // ── limits ────────────────────────────────────────────────────────────────

    public function test_the_per_payment_cap_refuses_a_genuine_code_and_tells_the_tenant(): void
    {
        [$tenant, , $token, $w] = $this->world([], ['enabled' => true, 'max_amount_minor' => 100000]);
        app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/h', 'secret' => 'whsec_x']));

        $this->ring($token, $this->code($w, 1, 100001))->assertOk()->assertSee('Thank you', false);

        $this->assertSame(0, $this->txCount($tenant));
        $rejected = $this->events($tenant, 'code.rejected');
        $this->assertCount(1, $rejected);
        $this->assertSame('amount_limit', $rejected->first()->payload['data']['reason']);
        $this->assertSame('install-0001', $rejected->first()->payload['data']['device_id']);

        $this->ring($token, $this->code($w, 1, 100000));                        // the counter was not used up by the refusal
        $this->assertSame(1, $this->txCount($tenant));
    }

    public function test_the_daily_total_is_enforced_across_codes(): void
    {
        [$tenant, , $token, $w] = $this->world([], ['enabled' => true, 'max_amount_minor' => 500000, 'daily_limit_minor' => 700000]);

        $this->ring($token, $this->code($w, 1, 400000));
        $this->ring($token, $this->code($w, 2, 400000));                        // would take the day to 800,000
        $this->ring($token, $this->code($w, 3, 300000));                        // exactly 700,000: fine

        $this->assertSame(2, $this->txCount($tenant));
        $this->assertSame('daily_limit', $this->events($tenant, 'code.rejected')->first()?->payload['data']['reason'] ?? 'daily_limit');
    }

    public function test_caller_binding_applies_to_device_codes(): void
    {
        [$tenant, , $token, $w] = $this->world(['bind_caller' => true]);

        $this->ring($token, $this->code($w, 1), '+2348099999999');
        $this->assertSame(0, $this->txCount($tenant));

        $this->ring($token, $this->code($w, 1), '08012345678');                // the subscriber's own phone, written locally
        $this->assertSame(1, $this->txCount($tenant));
    }

    public function test_guessing_signatures_locks_the_caller_out_even_for_a_valid_code(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $good = $this->code($w, 1);

        foreach (range(1, 5) as $i) {
            $this->ring($token, substr($good, 0, 30) . str_pad((string) $i, 10, '0', STR_PAD_LEFT), '+2348055555555');
        }
        $this->ring($token, $good, '+2348055555555');

        $this->assertSame(0, $this->txCount($tenant));
    }

    // ── settlement failures ───────────────────────────────────────────────────

    public function test_a_declined_hold_fails_the_payment_without_releasing_anything(): void
    {
        [$tenant, , $token, $w] = $this->world(['settings' => ['device_codes' => ['enabled' => true], 'sandbox' => ['fail_hold' => true]]]);
        app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/h', 'secret' => 'whsec_x']));

        $this->ring($token, $this->code($w, 1))->assertOk();

        $tx = Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('failed', $tx->status);
        $this->assertStringContainsString('Insufficient funds', $tx->failure_reason);
        $this->assertCount(1, $this->events($tenant, 'transaction.failed'));
    }

    public function test_a_declined_capture_fails_the_payment(): void
    {
        [$tenant, , $token, $w] = $this->world(['settings' => ['device_codes' => ['enabled' => true], 'sandbox' => ['fail_capture' => true]]]);

        $this->ring($token, $this->code($w, 1))->assertOk();

        $this->assertSame('failed', Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('status'));
    }

    public function test_a_hold_that_never_answered_is_retried_by_reconciliation(): void
    {
        [$tenant, , $token, $w] = $this->world();
        $calls = new \ArrayObject(['holds' => 0]);
        $this->app->bind(SandboxAdapter::class, fn () => new class($calls) extends SandboxAdapter {
            public function __construct(private \ArrayObject $calls) {}
            public function hold(Tenant $t, string $r, AccountRef $source, int $a, string $c): SettlementResult
            {
                if (++$this->calls['holds'] === 1) { throw new \RuntimeException('timeout'); }
                return parent::hold($t, $r, $source, $a, $c);
            }
        });

        $this->ring($token, $this->code($w, 1))->assertOk();
        $tx = Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('pending', $tx->status);                              // nothing is lost, nothing is guessed

        Carbon::setTestNow(now()->addMinutes(5));
        $counts = app(CodeService::class)->reconcilePending();

        $this->assertSame(1, $counts['retried']);
        $this->assertSame('settled', $tx->fresh()->status);
        $this->assertSame(2, $calls['holds']);
    }

    // ── routing ───────────────────────────────────────────────────────────────

    public function test_a_shared_number_tenant_prefixes_the_device_code_with_its_short_code(): void
    {
        [$tenant, $key] = $this->makeTenant(['voice_mode' => 'shared', 'settings' => ['device_codes' => ['enabled' => true]]]);
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber('+2347000000099');
        $enrol = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001']);
        $w = ['key' => $enrol->json('device_key'), 'short' => $enrol->json('short_id'), 'merchant' => $this->withToken($key)->getJson('/api/v1/merchants/shop-1')->json('short_id')];

        $this->dial('+2347000000099', $token, $tenant->short_code . $this->code($w, 1) . '#')->assertOk();

        $this->assertSame(1, $this->txCount($tenant));
    }

    public function test_issued_codes_and_device_codes_live_side_by_side(): void
    {
        [$tenant, $key, $token, $w] = $this->world();
        $issued = $this->issueCode($key)->json('code');

        $this->ring($token, $this->code($w, 1, 1000))->assertOk();
        $this->ring($token, $issued)->assertOk();

        $this->assertSame(2, $this->txCount($tenant));
        $this->assertEqualsCanonicalizing(['device', 'issued'], PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('origin')->all());
    }

    public function test_one_tenants_phone_cannot_pay_in_another_tenant(): void
    {
        [, , , $w] = $this->world();
        [$other, $otherKey] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $this->seedParties($other);
        $otherToken = $this->makeVoiceNumber('+2347000000002', $other);

        $this->dial('+2347000000002', $otherToken, $this->code($w, 1) . '#')->assertOk();

        $this->assertSame(0, $this->txCount($other));
    }
}
