<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\PaymentCode;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\Totp;
use App\Services\Codes\CodeState;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.admin.require_two_factor' => false]);
    }

    private function user(Tenant $tenant, string $role = 'owner', array $extra = []): TenantUser
    {
        static $n = 0;
        $n++;

        return TenantUser::withoutGlobalScopes()->create($extra + [
            'tenant_id' => $tenant->id, 'name' => "User {$n}", 'email' => "user{$n}@example.com",
            'password' => 'correct-horse-battery', 'role' => $role, 'is_active' => true,
        ]);
    }

    /** A payment code, with a transaction in the given status. @return array{0: PaymentCode, 1: Transaction} */
    private function payment(Tenant $tenant, string $status = 'settled', int $amount = 250000, string $merchantRef = 'shop-1', ?string $failure = null): array
    {
        return app(TenantContext::class)->run($tenant, function () use ($tenant, $status, $amount, $merchantRef, $failure) {
            $sub = Subscriber::firstOrCreate(['reference' => 'sub-1'], ['phone' => '+2348012345678', 'account_number' => '2000000001', 'bank_code' => '058']);
            $mer = Merchant::firstOrCreate(['reference' => $merchantRef], ['name' => '=Evil Shop', 'account_number' => '3000000001', 'bank_code' => '011', 'account_reference' => 'acct-' . $merchantRef]);
            $code = PaymentCode::create([
                'uuid' => (string) Str::uuid(), 'subscriber_id' => $sub->id, 'merchant_id' => $mer->id, 'amount_minor' => $amount,
                'currency' => 'NGN', 'source_account_reference' => 'acct-payer', 'code_hash' => hash('sha256', Str::random(20)),
                'state' => $status === 'pending' ? CodeState::Redeemed : ($status === 'settled' ? CodeState::Settled : CodeState::Failed),
                'expires_at' => now()->addMinutes(5), 'redeemed_at' => now(), 'caller_number' => '+2348012345678',
            ]);
            $tx = Transaction::create([
                'uuid' => (string) Str::uuid(), 'payment_code_id' => $code->id, 'reference' => 'TX-' . Str::upper(Str::random(8)),
                'amount_minor' => $amount, 'currency' => 'NGN', 'status' => $status, 'failure_reason' => $failure,
                'settled_at' => $status === 'settled' ? now() : null,
            ]);

            return [$code, $tx];
        });
    }

    // ── Access ────────────────────────────────────────────────────────────────

    public function test_guests_are_sent_to_the_portal_login_not_the_admin_one(): void
    {
        $this->get('/portal')->assertRedirect(route('portal.login'));
        $this->get('/portal/transactions')->assertRedirect(route('portal.login'));
        $this->get('/admin/tenants')->assertRedirect(route('admin.login'));
    }

    public function test_tenant_staff_cannot_open_the_operator_console(): void
    {
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->user($tenant), 'tenant')->get('/admin/tenants')->assertRedirect(route('admin.login'));
    }

    public function test_operators_cannot_use_the_portal(): void
    {
        $admin = Admin::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a-long-password-1']);
        $this->actingAs($admin, 'admin')->get('/portal')->assertRedirect(route('portal.login'));
    }

    public function test_sign_in_with_the_right_password_opens_the_dashboard(): void
    {
        [$tenant] = $this->makeTenant();
        $u = $this->user($tenant);

        $this->post('/portal/login', ['email' => $u->email, 'password' => 'correct-horse-battery'])->assertRedirect(route('portal.dashboard'));
        $this->get('/portal')->assertOk()->assertSee($tenant->name);
        $this->assertNotNull($u->fresh()->last_login_at);
        $this->assertSame(1, AuditLog::where('tenant_id', $tenant->id)->where('event_type', 'portal.login')->count());
    }

    public function test_wrong_password_unknown_email_and_switched_off_users_all_get_the_same_answer(): void
    {
        [$tenant] = $this->makeTenant();
        $u = $this->user($tenant);
        $off = $this->user($tenant, 'viewer', ['is_active' => false]);

        foreach ([[$u->email, 'nope-nope-nope'], ['ghost@example.com', 'whatever-whatever'], [$off->email, 'correct-horse-battery']] as [$email, $pw]) {
            $this->post('/portal/login', ['email' => $email, 'password' => $pw])->assertSessionHasErrors(['email' => 'These credentials do not match.']);
        }
        $this->assertGuest('tenant');
    }

    public function test_an_invited_person_cannot_sign_in_until_they_set_a_password(): void
    {
        [$tenant] = $this->makeTenant();
        $u = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'New', 'email' => 'new@example.com', 'role' => 'viewer', 'is_active' => true]);

        $this->post('/portal/login', ['email' => 'new@example.com', 'password' => 'anything-at-all-1'])->assertSessionHasErrors('email');
    }

    public function test_the_invitation_link_sets_a_password_signs_in_once_and_dies(): void
    {
        [$tenant] = $this->makeTenant();
        $u = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'New', 'email' => 'new@example.com', 'role' => 'viewer', 'is_active' => true]);
        $token = TenantInvitation::issue($u);

        $this->get("/portal/invite/{$token}")->assertOk()->assertSee('new@example.com');
        $this->post("/portal/invite/{$token}", ['name' => 'Nia', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post("/portal/invite/{$token}", ['name' => 'Nia', 'password' => 'a-good-long-password', 'password_confirmation' => 'a-good-long-password'])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertAuthenticatedAs($u->fresh(), 'tenant');
        $this->assertSame('Nia', $u->fresh()->name);
        $this->get("/portal/invite/{$token}")->assertNotFound();
    }

    public function test_an_expired_or_made_up_invitation_is_not_found(): void
    {
        [$tenant] = $this->makeTenant();
        $u = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'N', 'email' => 'n@example.com', 'role' => 'viewer', 'is_active' => true]);
        $token = TenantInvitation::issue($u);
        TenantInvitation::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);

        $this->get("/portal/invite/{$token}")->assertNotFound();
        $this->get('/portal/invite/' . bin2hex(random_bytes(24)))->assertNotFound();
    }

    public function test_a_second_factor_is_asked_for_when_set_up(): void
    {
        [$tenant] = $this->makeTenant();
        $secret = Totp::generateSecret();
        $u = $this->user($tenant, 'owner', ['totp_secret' => $secret, 'totp_confirmed_at' => now()]);

        $this->post('/portal/login', ['email' => $u->email, 'password' => 'correct-horse-battery'])->assertRedirect(route('portal.two-factor'));
        $this->assertGuest('tenant');
        $this->post('/portal/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

        $code = Totp::code($secret, Totp::step(now()->timestamp));
        $this->post('/portal/two-factor', ['code' => $code])->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($u, 'tenant');
    }

    public function test_when_two_factor_is_required_the_portal_only_opens_the_setup_page(): void
    {
        config(['platform.admin.require_two_factor' => true]);
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->user($tenant), 'tenant');

        $this->get('/portal')->assertRedirect(route('portal.account'));
        $this->get('/portal/transactions')->assertRedirect(route('portal.account'));
        $this->get('/portal/account')->assertOk()->assertSee('Enter this key by hand');
    }

    public function test_a_user_switched_off_while_signed_in_is_thrown_out(): void
    {
        [$tenant] = $this->makeTenant();
        $u = $this->user($tenant);
        $this->actingAs($u, 'tenant')->get('/portal')->assertOk();

        $u->update(['is_active' => false]);
        $this->get('/portal')->assertRedirect(route('portal.login'));
    }

    // ── Isolation ─────────────────────────────────────────────────────────────

    public function test_a_tenant_sees_only_its_own_payments(): void
    {
        [$a] = $this->makeTenant();
        [$b] = $this->makeTenant();
        [, $txA] = $this->payment($a);
        [$codeB, $txB] = $this->payment($b);

        $this->actingAs($this->user($a), 'tenant');
        $this->get('/portal/transactions')->assertOk()->assertSee($txA->reference)->assertDontSee($txB->reference);
        $this->get("/portal/transactions/{$txA->uuid}")->assertOk();
        $this->get("/portal/transactions/{$txB->uuid}")->assertNotFound();
        $this->get("/portal/codes/{$codeB->uuid}")->assertNotFound();
        $this->post("/portal/codes/{$codeB->uuid}/cancel")->assertNotFound();
    }

    public function test_a_tenant_cannot_touch_another_tenants_keys_webhooks_deliveries_or_team(): void
    {
        [$a] = $this->makeTenant();
        [$b, $keyB] = $this->makeTenant();
        $owner = $this->user($a);
        $other = $this->user($b);
        $endpointB = app(TenantContext::class)->run($b, fn () => WebhookEndpoint::create(['url' => 'https://b.example.com/h', 'secret' => 'whsec_x']));
        $deliveryB = app(TenantContext::class)->run($b, fn () => WebhookDelivery::create(['tenant_id' => $b->id, 'webhook_endpoint_id' => $endpointB->id, 'event_id' => (string) Str::uuid(), 'event_type' => 'code.redeemed', 'payload' => [], 'status' => 'failed']));
        $keyBId = \App\Models\ApiKey::where('tenant_id', $b->id)->value('id');

        $this->actingAs($owner, 'tenant');
        $this->delete("/portal/developer/keys/{$keyBId}")->assertNotFound();
        $this->patch("/portal/developer/webhooks/{$endpointB->id}")->assertNotFound();
        $this->delete("/portal/developer/webhooks/{$endpointB->id}")->assertNotFound();
        $this->get("/portal/developer/deliveries/{$deliveryB->id}")->assertNotFound();
        $this->post("/portal/developer/deliveries/{$deliveryB->id}/retry")->assertNotFound();
        $this->patch("/portal/team/{$other->id}", ['role' => 'viewer'])->assertNotFound();
        $this->delete("/portal/team/{$other->id}")->assertNotFound();

        $this->assertNull(\App\Models\ApiKey::find($keyBId)->revoked_at);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_the_audit_log_shows_only_the_tenants_own_entries(): void
    {
        [$a] = $this->makeTenant();
        [$b] = $this->makeTenant();
        app(AuditLogService::class)->record($b->id, 'secret.event', 'system', null, null, null, 'only tenant b should ever see this');
        app(AuditLogService::class)->record($a->id, 'visible.event', 'system', null, null, null, 'tenant a entry');

        $this->actingAs($this->user($a), 'tenant')->get('/portal/audit')->assertOk()->assertSee('tenant a entry')->assertDontSee('only tenant b');
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public function test_a_developer_cannot_see_money_or_the_team_but_can_manage_integration(): void
    {
        [$tenant] = $this->makeTenant();
        [, $tx] = $this->payment($tenant);
        $this->actingAs($this->user($tenant, 'developer'), 'tenant');

        $this->get('/portal/transactions')->assertForbidden();
        $this->get("/portal/transactions/{$tx->uuid}")->assertForbidden();
        $this->get('/portal/codes')->assertForbidden();
        $this->get('/portal/subscribers')->assertForbidden();
        $this->get('/portal/audit')->assertForbidden();
        $this->get('/portal/team')->assertForbidden();
        $this->get('/portal/developer/keys')->assertOk();
        $this->post('/portal/developer/keys', ['name' => 'ci'])->assertSessionHas('secret');
        $this->get('/portal')->assertOk()->assertDontSee('Settled');
    }

    public function test_a_viewer_can_read_everything_but_change_nothing(): void
    {
        [$tenant] = $this->makeTenant();
        [$code, $tx] = $this->payment($tenant);
        $this->actingAs($this->user($tenant, 'viewer'), 'tenant');

        $this->get('/portal/transactions')->assertOk();
        $this->get("/portal/transactions/{$tx->uuid}")->assertOk();
        $this->get('/portal/developer/webhooks')->assertOk();
        $this->get('/portal/audit')->assertOk();
        $this->post('/portal/developer/keys', ['name' => 'x'])->assertForbidden();
        $this->post('/portal/developer/webhooks', ['url' => 'https://x.example.com/h'])->assertForbidden();
        $this->post("/portal/codes/{$code->uuid}/cancel")->assertForbidden();
        $this->get('/portal/team')->assertForbidden();
        $this->post('/portal/team', ['name' => 'x', 'email' => 'x@example.com', 'role' => 'owner'])->assertForbidden();
    }

    // ── Money views ───────────────────────────────────────────────────────────

    public function test_the_dashboard_adds_up_the_period(): void
    {
        [$tenant] = $this->makeTenant();
        $this->payment($tenant, 'settled', 250000);
        $this->payment($tenant, 'settled', 100000);
        $this->payment($tenant, 'failed', 50000, 'shop-1', 'Insufficient funds');
        $this->payment($tenant, 'pending', 70000);

        $this->actingAs($this->user($tenant), 'tenant')->get('/portal?period=7d')
            ->assertOk()->assertSee('₦3,500.00')->assertSee('66.7%')->assertSee('Insufficient funds');
    }

    public function test_transactions_can_be_filtered(): void
    {
        [$tenant] = $this->makeTenant();
        [, $big] = $this->payment($tenant, 'settled', 900000);
        [, $small] = $this->payment($tenant, 'settled', 10000, 'shop-2');
        [, $bad] = $this->payment($tenant, 'failed', 20000, 'shop-1', 'No funds');
        $this->actingAs($this->user($tenant), 'tenant');

        $refs = fn (string $query) => collect(preg_match_all('/TX-[A-Z0-9]{8}/', $this->get('/portal/transactions' . $query)->assertOk()->getContent(), $m) ? $m[0] : [])->unique()->sort()->values()->all();
        $only = fn (...$txs) => collect($txs)->map->reference->sort()->values()->all();

        $this->assertSame($only($bad), $refs('?status=failed'));
        $this->assertSame($only($big, $small, $bad), $refs('?min=50'));          // ₦50 and up: all three here
        $this->assertSame($only($big, $bad), $refs('?min=150'));                  // ₦150 and up drops the ₦100 one
        $this->assertSame($only($small), $refs('?merchant=shop-2'));
        $this->assertSame($only($big), $refs('?q=' . $big->reference));
        $this->assertSame($only($small), $refs('?max=100'));
        $this->get('/portal/transactions?min=abc')->assertSessionHasErrors('min');
    }

    public function test_payer_phone_numbers_are_masked_everywhere(): void
    {
        [$tenant] = $this->makeTenant();
        [$code, $tx] = $this->payment($tenant);
        $this->actingAs($this->user($tenant), 'tenant');

        $this->get("/portal/transactions/{$tx->uuid}")->assertDontSee('+2348012345678')->assertSee('+23480••••78');
        $this->get('/portal/subscribers')->assertDontSee('+2348012345678')->assertSee('+23480••••78');
        $this->get("/portal/codes/{$code->uuid}")->assertDontSee('+2348012345678');
    }

    public function test_the_csv_export_is_scoped_and_safe_to_open_in_a_spreadsheet(): void
    {
        [$a] = $this->makeTenant();
        [$b] = $this->makeTenant();
        [, $txA] = $this->payment($a);
        [, $txB] = $this->payment($b);

        $response = $this->actingAs($this->user($a), 'tenant')->get('/portal/transactions/export');
        $csv = $response->streamedContent();

        $this->assertStringContainsString($txA->reference, $csv);
        $this->assertStringNotContainsString($txB->reference, $csv);
        $this->assertStringContainsString("'=Evil Shop", $csv);   // a leading = would run as a formula
        $this->assertStringNotContainsString('+2348012345678', $csv);
    }

    // ── Codes ─────────────────────────────────────────────────────────────────

    public function test_an_owner_can_cancel_an_unredeemed_code_and_a_redeemed_one_is_refused(): void
    {
        [$tenant] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->actingAs($this->user($tenant), 'tenant');

        $uuid = app(TenantContext::class)->run($tenant, fn () => PaymentCode::create([
            'uuid' => (string) Str::uuid(), 'subscriber_id' => Subscriber::first()->id, 'merchant_id' => Merchant::first()->id,
            'amount_minor' => 1000, 'currency' => 'NGN', 'source_account_reference' => 'a', 'code_hash' => hash('sha256', 'x'),
            'state' => CodeState::Issued, 'expires_at' => now()->addMinutes(5),
        ])->uuid);

        $this->post("/portal/codes/{$uuid}/cancel")->assertRedirect(route('portal.codes.show', $uuid));
        $this->assertSame('cancelled', PaymentCode::withoutGlobalScopes()->where('uuid', $uuid)->value('state')->value);
        $this->assertSame(1, AuditLog::where('tenant_id', $tenant->id)->where('event_type', 'portal.code_cancelled')->count());

        [$settled] = $this->payment($tenant);
        $this->post("/portal/codes/{$settled->uuid}/cancel")->assertSessionHasErrors('code');
    }

    // ── Developer tools ───────────────────────────────────────────────────────

    public function test_keys_can_be_issued_once_and_revoked_and_the_audit_log_notes_it(): void
    {
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->user($tenant), 'tenant');

        $r = $this->post('/portal/developer/keys', ['name' => 'prod']);
        $plain = session('secret')['value'];
        $this->assertMatchesRegularExpression('/^opk_[0-9a-f]{8}_[0-9a-f]{40}$/', $plain);
        $this->get('/portal/developer/keys')->assertSee($plain);      // shown once, on the page after creating it…
        $this->get('/portal/developer/keys')->assertDontSee($plain);  // …and never again

        $this->withToken($plain)->getJson('/api/v1/codes/' . Str::uuid())->assertNotFound(); // the key authenticates (404 = reached the controller)

        $id = \App\Models\ApiKey::where('tenant_id', $tenant->id)->where('name', 'prod')->value('id');
        $this->delete("/portal/developer/keys/{$id}")->assertRedirect();
        $this->flushHeaders()->withToken($plain)->getJson('/api/v1/codes/' . Str::uuid())->assertUnauthorized();
        $this->assertSame(2, AuditLog::where('tenant_id', $tenant->id)->whereIn('event_type', ['portal.key_issued', 'portal.key_revoked'])->count());
    }

    public function test_webhook_endpoints_can_be_added_rotated_tested_paused_and_deleted(): void
    {
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->user($tenant), 'tenant');

        $this->post('/portal/developer/webhooks', ['url' => 'https://hooks.example.com/pk', 'events' => ['transaction.settled']])->assertSessionHas('secret');
        $endpoint = app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::first());
        $first = $endpoint->secret;
        $this->assertSame(['transaction.settled'], $endpoint->events);
        $this->get('/portal/developer/webhooks')->assertSee($first);
        $this->get('/portal/developer/webhooks')->assertDontSee($first);

        $this->post("/portal/developer/webhooks/{$endpoint->id}/secret")->assertSessionHas('secret');
        $this->assertNotSame($first, app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::first()->secret));

        $this->patch("/portal/developer/webhooks/{$endpoint->id}")->assertRedirect();
        $this->assertFalse((bool) app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::first()->active));

        $this->post("/portal/developer/webhooks/{$endpoint->id}/test")->assertRedirect();
        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_type', 'webhook.test')->count());

        $this->delete("/portal/developer/webhooks/{$endpoint->id}")->assertRedirect();
        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_failed_delivery_can_be_retried_and_a_delivered_one_cannot(): void
    {
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->user($tenant), 'tenant');
        $endpoint = app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/pk', 'secret' => 'whsec_x']));
        $mk = fn (string $status) => WebhookDelivery::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => (string) Str::uuid(), 'event_type' => 'code.redeemed', 'payload' => ['data' => []], 'status' => $status, 'attempts' => 7]);
        \Illuminate\Support\Facades\Queue::fake();

        $failed = $mk('failed');
        $this->post("/portal/developer/deliveries/{$failed->id}/retry")->assertRedirect();
        $fresh = WebhookDelivery::withoutGlobalScopes()->find($failed->id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\DeliverWebhook::class);

        $done = $mk('delivered');
        $this->post("/portal/developer/deliveries/{$done->id}/retry")->assertStatus(409);
    }

    // ── Team ──────────────────────────────────────────────────────────────────

    public function test_an_owner_can_invite_change_roles_and_remove_but_never_orphan_the_tenant(): void
    {
        [$tenant] = $this->makeTenant();
        $me = $this->user($tenant);
        $this->actingAs($me, 'tenant');

        $this->post('/portal/team', ['name' => 'Dev', 'email' => 'dev@example.com', 'role' => 'developer'])->assertSessionHas('secret');
        $dev = TenantUser::where('email', 'dev@example.com')->first();
        $this->assertNull($dev->password);
        $this->assertSame($tenant->id, $dev->tenant_id);
        $this->post('/portal/team', ['name' => 'Dup', 'email' => 'dev@example.com', 'role' => 'viewer'])->assertSessionHasErrors('email');

        $this->patch("/portal/team/{$dev->id}/role", ['role' => 'viewer'])->assertRedirect();
        $this->assertSame('viewer', $dev->fresh()->role);

        // Not yourself, and not the last owner.
        $this->patch("/portal/team/{$me->id}/role", ['role' => 'viewer'])->assertSessionHasErrors('user');
        $this->delete("/portal/team/{$me->id}")->assertSessionHasErrors('user');
        $second = $this->user($tenant);
        $this->actingAs($second, 'tenant');
        $this->patch("/portal/team/{$me->id}/role", ['role' => 'viewer'])->assertRedirect();   // fine: $second remains an owner
        $this->patch("/portal/team/{$me->id}/role", ['role' => 'owner'])->assertRedirect();

        $this->delete("/portal/team/{$dev->id}")->assertRedirect();
        $this->assertNull(TenantUser::find($dev->id));
    }

    public function test_the_last_active_owner_cannot_be_switched_off_by_another_owner(): void
    {
        [$tenant] = $this->makeTenant();
        $a = $this->user($tenant);
        $b = $this->user($tenant);
        $this->actingAs($a, 'tenant');

        $this->patch("/portal/team/{$b->id}")->assertRedirect();      // b off, a is still an owner
        $this->assertFalse($b->fresh()->is_active);
        $this->patch("/portal/team/{$b->id}")->assertRedirect();      // b back on
        $b->update(['role' => 'viewer']);
        $this->actingAs($b, 'tenant');
        $this->get('/portal/team')->assertForbidden();
    }

    public function test_changing_your_password_needs_the_current_one(): void
    {
        [$tenant] = $this->makeTenant();
        $u = $this->user($tenant);
        $this->actingAs($u, 'tenant');

        $this->post('/portal/account/password', ['current_password' => 'wrong-wrong-wrong', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])->assertSessionHasErrors('current_password');
        $this->post('/portal/account/password', ['current_password' => 'correct-horse-battery', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-long-password', $u->fresh()->password));
    }

    public function test_the_tenants_audit_chain_stays_intact_through_portal_activity(): void
    {
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->user($tenant), 'tenant');
        $this->post('/portal/developer/keys', ['name' => 'a']);
        $this->post('/portal/developer/webhooks', ['url' => 'https://hooks.example.com/pk']);
        $this->post('/portal/audit/verify')->assertSessionHas('status');

        $this->assertSame([], app(AuditLogService::class)->verifyChain($tenant->id));
    }

    // ── Operator side ─────────────────────────────────────────────────────────

    public function test_an_operator_can_give_a_tenant_its_first_owner_and_recover_access(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = Admin::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a-long-password-1']);
        $this->actingAs($admin, 'admin');

        $this->post("/admin/tenants/{$tenant->id}/portal-users", ['name' => 'Ola', 'email' => 'ola@bank.example', 'role' => 'owner'])->assertSessionHas('secret');
        $u = TenantUser::withoutGlobalScopes()->where('email', 'ola@bank.example')->first();
        $this->assertSame($tenant->id, $u->tenant_id);
        $this->get("/admin/tenants/{$tenant->id}")->assertOk()->assertSee('ola@bank.example');

        $u->forceFill(['totp_secret' => Totp::generateSecret(), 'totp_confirmed_at' => now()])->save();
        $this->post("/admin/tenants/{$tenant->id}/portal-users/{$u->id}/reset-2fa")->assertRedirect();
        $this->assertFalse($u->fresh()->hasTwoFactor());
        $this->patch("/admin/tenants/{$tenant->id}/portal-users/{$u->id}")->assertRedirect();
        $this->assertFalse($u->fresh()->is_active);

        // A portal user of another tenant is not reachable through this tenant's URL.
        [$other] = $this->makeTenant();
        $this->post("/admin/tenants/{$other->id}/portal-users/{$u->id}/reset-2fa")->assertNotFound();
    }

    public function test_the_security_headers_still_forbid_inline_scripts_on_portal_pages(): void
    {
        [$tenant] = $this->makeTenant();
        $csp = $this->actingAs($this->user($tenant), 'tenant')->get('/portal')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp)[0]);
        $this->assertStringNotContainsString("script-src", $csp);
    }
}
