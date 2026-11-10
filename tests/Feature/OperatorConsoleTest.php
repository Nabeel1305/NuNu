<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ApiKey;
use App\Models\OfflineDevice;
use App\Models\PaymentCode;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Transaction;
use App\Models\VoiceNumber;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Alerts\AlertService;
use App\Support\PlatformSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OperatorConsoleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.admin.require_two_factor' => false]);
        Cache::flush();
    }

    private function op(string $role = 'owner', array $extra = []): Admin
    {
        static $n = 0;
        $n++;

        // fresh(): load the database defaults (session epoch and the like), as a real sign-in would.
        return Admin::create($extra + ['name' => "Op {$n}", 'email' => "op{$n}@example.test", 'password' => 'a-long-test-password', 'role' => $role])->fresh();
    }

    private function as(Admin $a): static
    {
        return $this->actingAs($a, 'admin');
    }

    /** A settled/pending payment through the real code path. @return array{0: Tenant, 1: string, 2: string} */
    private function pendingPayment(string $status = 'pending'): array
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $res = $this->withToken($key)->postJson('/api/v1/codes', ['subscriber_reference' => 'sub-1', 'merchant_reference' => 'shop-1', 'amount_minor' => 150000, 'currency' => 'NGN'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
        $code = PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $tx = Transaction::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'payment_code_id' => $code->id,
            'reference' => 'TXN-TESTREF000001', 'amount_minor' => 150000, 'currency' => 'NGN', 'status' => $status]);
        $code->forceFill(['state' => 'redeemed', 'redeemed_at' => now()])->save();

        return [$tenant, $tx->uuid, $tx->reference];
    }

    // ── roles ────────────────────────────────────────────────────────────────

    public function test_roles_decide_what_an_operator_can_reach_and_denials_are_logged(): void
    {
        [$tenant] = $this->makeTenant();
        $finance = $this->op('finance');
        $support = $this->op('support');

        $this->as($finance)->get('/admin/dashboard')->assertOk();
        $this->as($finance)->get('/admin/payments')->assertOk();
        $this->as($finance)->get('/admin/payments-export')->assertOk();
        $this->as($finance)->get('/admin/operators')->assertForbidden();
        $this->as($finance)->get('/admin/audit')->assertForbidden();
        $this->as($finance)->put("/admin/tenants/{$tenant->id}", [])->assertForbidden();

        $this->as($support)->get('/admin/audit')->assertOk();
        $this->as($support)->get('/admin/operators')->assertForbidden();
        $this->as($support)->get('/admin/settings')->assertForbidden();
        $this->as($support)->post("/admin/tenants/{$tenant->id}/keys", ['name' => 'x'])->assertForbidden();

        $this->assertTrue(PlatformAuditLog::where('event', 'admin.denied')->where('outcome', 'denied')->exists());
    }

    public function test_every_page_renders_for_an_owner(): void
    {
        [$tenant, $uuid] = $this->pendingPayment();
        $owner = $this->op();
        // Give the tenant page everything to draw: a number, people, an endpoint, a restricted and an expired key.
        $this->makeVoiceNumber('+2347000000077', $tenant);
        TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Owner One', 'email' => 'one@x.test', 'password' => 'correct-horse-battery', 'role' => 'owner', 'is_active' => true, 'last_login_at' => now()]);
        TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Invited', 'email' => 'two@x.test', 'role' => 'viewer', 'is_active' => true]);
        app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/h', 'secret' => 'whsec_x']));
        [$k] = ApiKey::issue($tenant, 'restricted');
        $k->update(['allowed_ips' => '203.0.113.9', 'expires_at' => now()->addDays(3), 'last_used_ip' => '203.0.113.9']);
        [$old] = ApiKey::issue($tenant, 'old');
        $old->update(['expires_at' => now()->subDay()]);
        foreach (['support', 'finance'] as $role) {
            $this->as($this->op($role))->get("/admin/tenants/{$tenant->id}")->assertOk()->assertSee('restricted')->assertSee('Owner One');
        }
        $this->as($owner)->get("/admin/tenants/{$tenant->id}")->assertOk()->assertSee('Emergency and lifecycle')->assertSee('Rotate');

        foreach (['/admin/dashboard', '/admin/tenants', "/admin/tenants/{$tenant->id}", '/admin/payments', "/admin/payments/{$uuid}", '/admin/payers', '/admin/recipients',
            '/admin/phones', '/admin/webhooks', '/admin/deliveries', '/admin/health', '/admin/changelog', '/admin/operators', '/admin/audit', '/admin/settings',
            '/admin/search?q=TXN', '/admin/password', '/admin/security'] as $url) {
            $this->as($owner)->get($url)->assertOk();
        }
    }

    // ── operators and passwords ──────────────────────────────────────────────

    public function test_an_owner_adds_an_operator_with_a_temporary_password_they_must_replace(): void
    {
        $owner = $this->op();
        $res = $this->as($owner)->post('/admin/operators', ['name' => 'New Person', 'email' => 'new@example.test', 'role' => 'support']);
        $temp = session('secret')['value'] ?? null;

        $this->assertNotNull($temp);
        $new = Admin::where('email', 'new@example.test')->first();
        $this->assertTrue($new->must_change_password);
        $this->assertSame('support', $new->role);

        // Signed in with the temporary password, every page sends them to change it.
        $this->app['auth']->forgetGuards();
        $this->post('/admin/login', ['email' => 'new@example.test', 'password' => $temp]);
        $this->get('/admin/payments')->assertRedirect(route('admin.password'));
        $this->get('/admin/password')->assertOk();

        $this->put('/admin/password', ['current_password' => $temp, 'password' => 'Another-long-pass-9', 'password_confirmation' => 'Another-long-pass-9'])->assertRedirect(route('admin.dashboard'));
        $this->assertFalse($new->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Another-long-pass-9', $new->fresh()->password));
        $this->get('/admin/payments')->assertOk();
    }

    public function test_password_change_needs_the_current_one_and_a_strong_new_one(): void
    {
        $op = $this->op();
        $this->as($op)->put('/admin/password', ['current_password' => 'wrong', 'password' => 'Another-long-pass-9', 'password_confirmation' => 'Another-long-pass-9'])->assertSessionHasErrors('current_password');
        $this->as($op)->put('/admin/password', ['current_password' => 'a-long-test-password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('a-long-test-password', $op->fresh()->password));
    }

    public function test_there_is_always_one_active_owner_and_you_cannot_remove_yourself(): void
    {
        $owner = $this->op();
        $this->as($owner)->patch("/admin/operators/{$owner->id}", [])->assertStatus(422);
        $this->as($owner)->delete("/admin/operators/{$owner->id}")->assertStatus(422);
        $this->as($owner)->patch("/admin/operators/{$owner->id}/role", ['role' => 'support'])->assertStatus(422);

        $second = $this->op();
        $this->as($owner)->patch("/admin/operators/{$owner->id}/role", ['role' => 'support'])->assertRedirect();
        $this->assertSame('support', $owner->fresh()->role);
        $this->assertSame('owner', $second->fresh()->role);
    }

    public function test_switching_an_operator_off_signs_them_out_at_once(): void
    {
        $owner = $this->op();
        $other = $this->op('support');
        $this->as($other)->get('/admin/dashboard')->assertOk();

        $this->as($owner)->patch("/admin/operators/{$other->id}")->assertRedirect();

        $this->as($other->fresh())->get('/admin/dashboard')->assertRedirect(route('admin.login'));
    }

    public function test_an_idle_operator_is_signed_out_and_sign_out_everywhere_works(): void
    {
        $op = $this->op();
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 9, 0, 0));
        $this->as($op)->get('/admin/dashboard')->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 11, 11, 10, 5, 0));
        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
        Carbon::setTestNow();

        $op2 = $this->op();
        $this->as($op2)->get('/admin/dashboard')->assertOk();
        $before = $op2->fresh()->session_epoch;
        $this->post('/admin/sign-out-everywhere')->assertRedirect();
        $this->assertSame($before + 1, $op2->fresh()->session_epoch);
        $this->get('/admin/dashboard')->assertOk();                 // this session is kept
        $op2->forceFill(['session_epoch' => $op2->session_epoch + 1])->save();   // another session signs out everywhere
        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
    }

    // ── platform audit log ───────────────────────────────────────────────────

    public function test_changes_and_sign_ins_are_in_the_platform_audit_log_without_secrets(): void
    {
        $owner = $this->op();
        [$tenant] = $this->makeTenant();

        $this->post('/admin/login', ['email' => $owner->email, 'password' => 'wrong-password-here']);
        $this->post('/admin/login', ['email' => $owner->email, 'password' => 'a-long-test-password']);
        $this->post("/admin/tenants/{$tenant->id}/keys", ['name' => 'ci-key']);

        $this->assertTrue(PlatformAuditLog::where('event', 'admin.login_failed')->where('outcome', 'failed')->exists());
        $this->assertTrue(PlatformAuditLog::where('event', 'admin.login')->where('admin_id', $owner->id)->exists());
        $row = PlatformAuditLog::where('event', 'admin.keys.store')->first();
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertSame($owner->email, $row->admin_email);

        $all = json_encode(PlatformAuditLog::all()->toArray());
        $this->assertStringNotContainsString('a-long-test-password', $all);
        $this->assertStringNotContainsString('wrong-password-here', $all);

        $this->as($owner)->get('/admin/audit?event=admin.login')->assertOk()->assertSee('admin.login');
        $csv = $this->as($owner)->get('/admin/audit-export')->streamedContent();
        $this->assertStringContainsString('admin.keys.store', $csv);
    }

    // ── payments ─────────────────────────────────────────────────────────────

    public function test_payments_are_searchable_across_tenants_and_a_reference_search_jumps_to_it(): void
    {
        [, $uuid, $ref] = $this->pendingPayment();
        $owner = $this->op();

        $this->as($owner)->get('/admin/payments?q=' . $ref)->assertOk()->assertSee($ref);
        $this->as($owner)->get('/admin/payments?status=settled')->assertOk()->assertDontSee($ref);
        $this->as($owner)->get('/admin/payments?q=2000000001')->assertOk()->assertSee($ref);
        $this->as($owner)->get('/admin/search?q=' . $ref)->assertRedirect(route('admin.payments.show', $uuid));
        $this->as($owner)->get('/admin/search?q=Tenant')->assertOk()->assertSee('Tenants');
        $this->as($owner)->get('/admin/payments?stuck=1')->assertOk()->assertDontSee($ref);   // too new to count as stuck
        Carbon::setTestNow(now()->addHour());
        $this->as($owner)->get('/admin/payments?stuck=1')->assertOk()->assertSee($ref);
        Carbon::setTestNow();
    }

    public function test_the_payments_csv_hides_phone_numbers_and_blocks_formula_injection(): void
    {
        [$tenant, , $ref] = $this->pendingPayment();
        $tenant->update(['name' => '=cmd|calc']);
        $csv = $this->as($this->op('finance'))->get('/admin/payments-export')->streamedContent();

        $this->assertStringContainsString($ref, $csv);
        $this->assertStringNotContainsString('+2348012345678', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    public function test_marking_a_stuck_payment_failed_asks_the_tenant_first_and_is_logged(): void
    {
        [$tenant, $uuid, $ref] = $this->pendingPayment();
        $support = $this->op('support');

        $this->as($support)->post("/admin/payments/{$uuid}/mark-failed", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->as($support)->post("/admin/payments/{$uuid}/mark-failed", ['reason' => 'Bank confirms nothing moved']);

        $tx = Transaction::withoutGlobalScopes()->where('uuid', $uuid)->first();
        $this->assertSame('failed', $tx->status);
        $this->assertStringContainsString('Bank confirms nothing moved', $tx->failure_reason);
        $this->assertTrue(PlatformAuditLog::where('event', 'admin.payments.mark-failed')->where('tenant_id', $tenant->id)->exists());
        $this->assertSame([], app(\App\Services\Audit\AuditLogService::class)->verifyChain($tenant->id));

        // Already closed: a second attempt changes nothing.
        $this->as($support)->post("/admin/payments/{$uuid}/mark-failed", ['reason' => 'And again please']);
        $this->assertSame(1, \App\Models\AuditLog::where('tenant_id', $tenant->id)->where('event_type', 'transaction.failed')->count());
    }

    public function test_recheck_and_cancel_are_for_support_and_owner_only(): void
    {
        [, $uuid] = $this->pendingPayment();
        $this->as($this->op('finance'))->post("/admin/payments/{$uuid}/recheck")->assertForbidden();
        $this->as($this->op('finance'))->post("/admin/payments/{$uuid}/mark-failed", ['reason' => 'because I say'])->assertForbidden();
        $this->as($this->op('support'))->post("/admin/payments/{$uuid}/recheck")->assertRedirect();
    }

    public function test_an_unredeemed_code_can_be_cancelled_by_an_operator(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->withToken($key)->postJson('/api/v1/codes', ['subscriber_reference' => 'sub-1', 'merchant_reference' => 'shop-1', 'amount_minor' => 5000, 'currency' => 'NGN'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
        $code = PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->as($this->op('support'))->post("/admin/codes/{$code->uuid}/cancel")->assertRedirect();

        $this->assertSame('cancelled', $code->fresh()->state->value);
        $this->assertTrue(\App\Models\AuditLog::where('tenant_id', $tenant->id)->where('event_type', 'code.cancelled')->where('actor_type', 'admin')->exists());
    }

    // ── phones and webhooks ──────────────────────────────────────────────────

    public function test_an_operator_can_list_and_switch_off_a_phone(): void
    {
        [$tenant, $key] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $this->seedParties($tenant);
        $enrol = $this->withToken($key)->postJson('/api/v1/subscribers/sub-1/devices', ['device_id' => 'install-0001'])->assertCreated();
        $device = OfflineDevice::withoutGlobalScopes()->first();

        $this->as($this->op('finance'))->get('/admin/phones')->assertOk()->assertSee($enrol->json('short_id'));
        $this->as($this->op('finance'))->post("/admin/phones/{$device->id}/revoke")->assertForbidden();
        $this->as($this->op('support'))->post("/admin/phones/{$device->id}/revoke")->assertRedirect();

        $this->assertSame('revoked', $device->fresh()->status);
        $this->assertTrue(\App\Models\AuditLog::where('tenant_id', $tenant->id)->where('event_type', 'device.revoked')->where('actor_type', 'admin')->exists());
        $this->as($this->op())->get('/admin/phones?status=revoked')->assertOk()->assertSee($enrol->json('short_id'));
    }

    public function test_deliveries_can_be_retried_and_endpoints_tested_paused_and_rotated(): void
    {
        Http::fake();
        \Illuminate\Support\Facades\Queue::fake();
        [$tenant] = $this->makeTenant();
        $endpoint = app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/h', 'secret' => 'whsec_old']));
        $delivery = WebhookDelivery::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => 'transaction.settled', 'payload' => ['data' => ['reference' => 'X']], 'status' => 'failed', 'attempts' => 6, 'last_error' => 'timeout']);
        $support = $this->op('support');

        $this->as($support)->get('/admin/deliveries?status=failed')->assertOk()->assertSee('transaction.settled');
        $this->as($support)->get("/admin/deliveries/{$delivery->id}")->assertOk();
        $this->as($support)->post("/admin/deliveries/{$delivery->id}/retry")->assertRedirect();
        $this->assertSame(['pending', 0], [$delivery->fresh()->status, $delivery->fresh()->attempts]);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\DeliverWebhook::class);

        $this->as($support)->post("/admin/webhooks/{$endpoint->id}/test")->assertRedirect();
        $this->assertTrue(WebhookDelivery::withoutGlobalScopes()->where('event_type', 'webhook.test')->exists());
        $this->as($support)->patch("/admin/webhooks/{$endpoint->id}")->assertRedirect();
        $this->assertFalse(WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id)->active);
        $this->as($support)->post("/admin/webhooks/{$endpoint->id}/secret")->assertRedirect();
        $this->assertNotSame('whsec_old', WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id)->secret);
        $this->as($this->op('finance'))->post("/admin/deliveries/{$delivery->id}/retry")->assertForbidden();
    }

    // ── tenants ──────────────────────────────────────────────────────────────

    private function form(Tenant $t, array $over = []): array
    {
        $t = $t->fresh();

        return $over + ['status' => $t->status, 'environment' => $t->environment, 'settlement_adapter' => $t->settlement_adapter, 'voice_mode' => $t->voice_mode, 'code_ttl_minutes' => $t->code_ttl_minutes];
    }

    public function test_going_live_is_refused_with_the_sandbox_adapter_and_the_page_shows_the_go_live_checklist(): void
    {
        [$tenant] = $this->makeTenant();
        $owner = $this->op();

        // Only the sandbox adapter exists today, so a tenant cannot go live yet: that guard must hold.
        $this->as($owner)->put("/admin/tenants/{$tenant->id}", $this->form($tenant, ['environment' => 'live', 'confirm_live' => $tenant->slug, 'override_checklist' => '1']))->assertSessionHasErrors('environment');
        $this->assertSame('sandbox', $tenant->fresh()->environment);

        $this->as($owner)->get("/admin/tenants/{$tenant->id}")->assertOk()
            ->assertSee('Go-live checklist')->assertSee('A real settlement adapter')->assertSee('An owner has two-factor on')->assertSee('to do');
    }

    public function test_the_operator_can_set_direct_payment_options_and_the_short_code(): void
    {
        [$tenant] = $this->makeTenant(['voice_mode' => 'shared']);
        $this->as($this->op())->put("/admin/tenants/{$tenant->id}", $this->form($tenant, ['device_codes_enabled' => '1', 'direct_fields' => '1', 'direct_enabled' => '1', 'direct_max_amount_minor' => 99000, 'short_code' => '4321']))->assertSessionHasNoErrors();

        $t = $tenant->fresh();
        $this->assertTrue($t->setting('device_codes.direct_enabled'));
        $this->assertSame(99000, $t->setting('device_codes.direct_max_amount_minor'));
        $this->assertSame('4321', $t->short_code);

        $this->as($this->op())->put("/admin/tenants/{$tenant->id}", $this->form($tenant, ['short_code' => '12']))->assertSessionHasErrors('short_code');
    }

    public function test_archive_blocks_everything_and_delete_is_only_for_tenants_with_no_history(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $user = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'A', 'email' => 'a@x.test', 'password' => 'correct-horse-battery', 'role' => 'owner', 'is_active' => true]);
        $owner = $this->op();

        $this->as($owner)->post("/admin/tenants/{$tenant->id}/archive", ['confirm_slug' => 'nope'])->assertSessionHasErrors('confirm_slug');
        $this->as($owner)->post("/admin/tenants/{$tenant->id}/archive", ['confirm_slug' => $tenant->slug])->assertRedirect(route('admin.tenants.index'));

        $this->assertSame('archived', $tenant->fresh()->status);
        $this->assertNull(ApiKey::where('tenant_id', $tenant->id)->whereNull('revoked_at')->first());
        $this->withToken($key)->getJson('/api/v1/merchants/shop-1')->assertStatus(401);
        $this->actingAs($user, 'tenant')->get('/portal')->assertForbidden();
        $this->as($owner)->get('/admin/tenants')->assertOk()->assertDontSee($tenant->slug);
        $this->as($owner)->get('/admin/tenants?status=archived')->assertOk()->assertSee($tenant->slug);

        $this->as($owner)->delete("/admin/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])->assertRedirect();     // no payments yet: allowed
        $this->assertNull(Tenant::find($tenant->id));

        [$busy] = $this->pendingPayment();
        $this->as($owner)->post("/admin/tenants/{$busy->id}/archive", ['confirm_slug' => $busy->slug]);
        $this->as($owner)->delete("/admin/tenants/{$busy->id}", ['confirm_slug' => $busy->slug])->assertSessionHasErrors('confirm_slug');
        $this->assertNotNull(Tenant::find($busy->id));
        $this->as($owner)->post("/admin/tenants/{$busy->id}/restore")->assertRedirect();
        $this->assertSame('suspended', $busy->fresh()->status);
    }

    public function test_tenant_list_filters_and_bulk_actions(): void
    {
        [$a] = $this->makeTenant(['name' => 'Alpha Bank']);
        [$b] = $this->makeTenant(['name' => 'Beta Pay', 'status' => 'suspended']);
        $owner = $this->op();

        $this->as($owner)->get('/admin/tenants?q=Alpha')->assertOk()->assertSee('Alpha Bank')->assertDontSee('Beta Pay');
        $this->as($owner)->get('/admin/tenants?status=suspended')->assertOk()->assertSee('Beta Pay')->assertDontSee('Alpha Bank');

        TenantUser::withoutGlobalScopes()->create(['tenant_id' => $a->id, 'name' => 'U1', 'email' => 'u1@x.test', 'password' => 'correct-horse-battery', 'role' => 'owner', 'is_active' => true]);
        TenantUser::withoutGlobalScopes()->create(['tenant_id' => $a->id, 'name' => 'U2', 'email' => 'u2@x.test', 'password' => 'correct-horse-battery', 'role' => 'viewer', 'is_active' => true]);
        $this->as($owner)->post("/admin/tenants/{$a->id}/portal-users-bulk", ['action' => 'off'])->assertRedirect();
        $this->assertSame(0, TenantUser::withoutGlobalScopes()->where('tenant_id', $a->id)->where('is_active', true)->count());
        $this->as($owner)->post("/admin/tenants/{$a->id}/portal-users-bulk", ['action' => 'on']);
        $this->assertSame(2, TenantUser::withoutGlobalScopes()->where('tenant_id', $a->id)->where('is_active', true)->count());
    }

    public function test_portal_user_roles_can_change_but_the_last_owner_stays(): void
    {
        [$tenant] = $this->makeTenant();
        $o = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o@x.test', 'password' => 'correct-horse-battery', 'role' => 'owner', 'is_active' => true]);
        $v = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'V', 'email' => 'v@x.test', 'password' => 'correct-horse-battery', 'role' => 'viewer', 'is_active' => true]);
        $support = $this->op('support');

        $this->as($support)->patch("/admin/tenants/{$tenant->id}/portal-users/{$v->id}/role", ['role' => 'developer'])->assertRedirect();
        $this->assertSame('developer', $v->fresh()->role);
        $this->as($support)->patch("/admin/tenants/{$tenant->id}/portal-users/{$o->id}/role", ['role' => 'viewer'])->assertStatus(422);
        $this->as($support)->delete("/admin/tenants/{$tenant->id}/portal-users/{$o->id}")->assertStatus(422);
        $this->as($support)->delete("/admin/tenants/{$tenant->id}/portal-users/{$v->id}")->assertRedirect();
        $this->assertNull(TenantUser::withoutGlobalScopes()->find($v->id));
    }

    // ── keys and numbers ─────────────────────────────────────────────────────

    public function test_api_keys_can_expire_be_limited_to_addresses_and_rotated_with_a_grace_period(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $owner = $this->op();
        $row = ApiKey::where('tenant_id', $tenant->id)->first();

        // Limited to an address the test client is not at.
        $this->as($owner)->patch("/admin/tenants/{$tenant->id}/keys/{$row->id}", ['allowed_ips' => '203.0.113.9'])->assertRedirect();
        $this->withToken($key)->getJson('/api/v1/merchants/shop-1')->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
        $this->as($owner)->patch("/admin/tenants/{$tenant->id}/keys/{$row->id}", ['allowed_ips' => '127.0.0.1'])->assertRedirect();
        $this->withToken($key)->getJson('/api/v1/merchants/shop-1')->assertOk();
        $this->assertSame('127.0.0.1', $row->fresh()->last_used_ip);
        $this->as($owner)->patch("/admin/tenants/{$tenant->id}/keys/{$row->id}", ['allowed_ips' => 'not-an-ip'])->assertSessionHasErrors('allowed_ips');

        // Rotation: the new key works, the old one works for the grace period then stops.
        $this->as($owner)->post("/admin/tenants/{$tenant->id}/keys/{$row->id}/rotate", ['grace_hours' => 2]);
        $new = session('secret')['value'];
        $this->withToken($new)->getJson('/api/v1/merchants/shop-1')->assertOk();
        $this->withToken($key)->getJson('/api/v1/merchants/shop-1')->assertOk();
        Carbon::setTestNow(now()->addHours(3));
        $this->withToken($key)->getJson('/api/v1/merchants/shop-1')->assertStatus(401);
        $this->withToken($new)->getJson('/api/v1/merchants/shop-1')->assertOk();
        Carbon::setTestNow();

        $this->as($owner)->post("/admin/tenants/{$tenant->id}/keys-revoke-all", ['confirm_slug' => 'wrong'])->assertSessionHasErrors('confirm_slug');
        $this->as($owner)->post("/admin/tenants/{$tenant->id}/keys-revoke-all", ['confirm_slug' => $tenant->slug])->assertRedirect();
        $this->withToken($new)->getJson('/api/v1/merchants/shop-1')->assertStatus(401);
    }

    public function test_voice_numbers_can_be_moved_between_tenants_and_deleted(): void
    {
        [$a] = $this->makeTenant();
        [$b] = $this->makeTenant();
        $this->makeVoiceNumber('+2347000000055', $a);
        $number = VoiceNumber::where('number', '+2347000000055')->first();
        $owner = $this->op();

        $this->as($owner)->post("/admin/tenants/{$a->id}/numbers/{$number->id}/reassign", ['to' => (string) $b->id])->assertRedirect();
        $this->assertSame($b->id, $number->fresh()->tenant_id);
        $this->as($owner)->post("/admin/tenants/{$b->id}/numbers/{$number->id}/reassign", ['to' => 'pool'])->assertRedirect();
        $this->assertNull($number->fresh()->tenant_id);
        $this->as($owner)->delete("/admin/tenants/{$a->id}/numbers/{$number->id}")->assertNotFound();      // not theirs any more

        $this->as($owner)->post("/admin/tenants/{$b->id}/numbers/{$number->id}/reassign", ['to' => 'pool'])->assertNotFound();
    }

    // ── platform settings, health, alerts, view-as ───────────────────────────

    public function test_platform_settings_override_the_defaults_and_can_be_cleared(): void
    {
        [$tenant] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $owner = $this->op();
        $default = \App\Services\Offline\DeviceCodeSettings::for($tenant)->maxAmountMinor;

        $this->as($owner)->put('/admin/settings', ['s' => ['device_codes_max_amount_minor' => '1234.50', 'alerts_stuck_minutes' => '5']])->assertRedirect();
        $this->assertSame(123450, PlatformSettings::effective('device_codes.max_amount_minor'));
        $this->assertSame(123450, \App\Services\Offline\DeviceCodeSettings::for($tenant->fresh())->maxAmountMinor);
        $this->assertSame(5, PlatformSettings::effective('alerts.stuck_minutes'));

        $this->as($owner)->put('/admin/settings', ['s' => ['device_codes_max_amount_minor' => '']])->assertRedirect();
        $this->assertSame($default, \App\Services\Offline\DeviceCodeSettings::for($tenant->fresh())->maxAmountMinor);
        $this->as($this->op('support'))->put('/admin/settings', [])->assertForbidden();
    }

    public function test_the_health_page_reports_problems_and_can_check_the_audit_chains(): void
    {
        $owner = $this->op();
        $this->as($owner)->get('/admin/health')->assertOk()->assertSee('Scheduler')->assertSee('Alerts');
        $this->as($owner)->post('/admin/health/verify-audit')->assertRedirect();
        $this->assertSame([], Cache::get('platform:audit:last')['broken']);

        Cache::forever('platform:scheduler:last', now()->timestamp);
        $checks = collect(\App\Http\Controllers\Admin\HealthController::checks())->keyBy('label');
        $this->assertSame('ok', $checks['Scheduler']['status']);
        $this->assertSame('warn', $checks['Alerts']['status']);
    }

    public function test_alerts_fire_once_for_stuck_payments_and_reach_slack(): void
    {
        Http::fake();
        config(['platform.alerts.slack_webhook' => 'https://hooks.slack.test/abc']);
        $this->pendingPayment();
        Carbon::setTestNow(now()->addHours(2));

        $alerts = app(AlertService::class);
        $this->assertSame(['stuck'], $alerts->check());
        Http::assertSent(fn ($r) => str_contains($r['text'], 'pending for over'));
        $this->assertSame([], $alerts->check(), 'the same problem is not repeated at once');
        Carbon::setTestNow();
    }

    public function test_the_console_layout_follows_the_portal_and_shows_only_what_the_role_allows(): void
    {
        $finance = $this->op('finance');
        $html = $this->as($finance)->get('/admin/dashboard')->getContent();
        $this->assertStringContainsString('sidebar-tenant', $html);
        $this->assertStringContainsString('Finance', $html);
        $this->assertStringContainsString('Payments', $html);
        $this->assertStringNotContainsString('/admin/operators', $html);
        $this->assertStringNotContainsString('/admin/settings', $html);
        $this->assertStringNotContainsString('/admin/audit', $html);

        $owner = $this->op();
        $html = $this->as($owner)->get('/admin/dashboard')->getContent();
        $this->assertStringContainsString('/admin/operators', $html);
        $this->assertStringContainsString('/admin/settings', $html);
    }

    public function test_finance_cannot_view_as_a_portal_user(): void
    {
        [$tenant] = $this->makeTenant();
        $u = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'A', 'email' => 'a@x.test', 'password' => 'correct-horse-battery', 'role' => 'owner', 'is_active' => true]);

        $this->as($this->op('finance'))->post("/admin/tenants/{$tenant->id}/portal-users/{$u->id}/impersonate", ['reason' => 'looking around'])->assertForbidden();
        $this->as($this->op('support'))->post("/admin/tenants/{$tenant->id}/portal-users/{$u->id}/impersonate", ['reason' => 'a customer ticket'])->assertRedirect(route('portal.dashboard'));
    }

    public function test_voice_numbers_including_the_shared_pool_can_be_listed_added_changed_and_removed(): void
    {
        [$a] = $this->makeTenant(['voice_mode' => 'shared', 'status' => 'active']);
        $pool = VoiceNumber::create(['tenant_id' => null, 'number' => '+2347000000099', 'webhook_token_hash' => hash('sha256', 'x')]);
        $owner = $this->op();

        $this->as($owner)->get('/admin/voice-numbers')->assertOk()->assertSee('+2347000000099')->assertSee('Shared pool');
        $this->as($this->op('finance'))->get('/admin/voice-numbers')->assertOk()->assertSee('+2347000000099');
        $this->as($this->op('finance'))->put("/admin/voice-numbers/{$pool->id}", ['number' => '+2342017001724'])->assertForbidden();

        // Change the placeholder to the real number; the callback token (and so the provider's URL) is untouched.
        $hash = $pool->webhook_token_hash;
        $this->as($owner)->put("/admin/voice-numbers/{$pool->id}", ['number' => '+2342017001724'])->assertRedirect();
        $this->assertSame('+2342017001724', $pool->fresh()->number);
        $this->assertSame($hash, $pool->fresh()->webhook_token_hash);
        $this->as($owner)->put("/admin/voice-numbers/{$pool->id}", ['number' => 'abc'])->assertSessionHasErrors('number');

        // The dial details a payer gets now carry the real number.
        $this->assertSame('+2342017001724', VoiceNumber::forDialing($a->fresh())->e164());

        // Add one to the pool and one to a tenant; each shows its callback URL once.
        $this->as($owner)->post('/admin/voice-numbers', ['number' => '+2347000000123', 'owner' => 'pool'])->assertRedirect();
        $this->assertStringContainsString('/api/voice/africastalking?token=', session('secret')['value']);
        $this->as($owner)->post('/admin/voice-numbers', ['number' => '+2347000000123', 'owner' => 'pool'])->assertSessionHasErrors('number');
        $this->as($owner)->post('/admin/voice-numbers', ['number' => '+2347000000124', 'owner' => (string) $a->id])->assertRedirect();
        $own = VoiceNumber::where('number', '+2347000000124')->first();
        $this->assertSame($a->id, $own->tenant_id);
        $this->assertTrue(\App\Models\AuditLog::where('tenant_id', $a->id)->where('event_type', 'admin.number_added')->exists());

        $this->as($owner)->patch("/admin/voice-numbers/{$own->id}")->assertRedirect();
        $this->assertFalse($own->fresh()->active);
        $this->as($owner)->post("/admin/voice-numbers/{$own->id}/reassign", ['to' => 'pool'])->assertRedirect();
        $this->assertNull($own->fresh()->tenant_id);
        $this->as($owner)->delete("/admin/voice-numbers/{$own->id}")->assertRedirect();
        $this->assertNull(VoiceNumber::find($own->id));
    }

    public function test_the_voice_numbers_page_warns_when_a_shared_tenant_has_no_number_to_ring(): void
    {
        $this->makeTenant(['name' => 'Shared Bank', 'voice_mode' => 'shared', 'status' => 'active']);
        $this->as($this->op())->get('/admin/voice-numbers')->assertOk()->assertSee('Shared Bank')->assertSee('no active number');
    }

    public function test_every_voice_callback_is_logged_with_a_reason_and_without_the_digits(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $token = $this->makeVoiceNumber('+2342017001724', $tenant);
        $issued = $this->withToken($key)->postJson('/api/v1/codes', ['subscriber_reference' => 'sub-1', 'merchant_reference' => 'shop-1', 'amount_minor' => 10000, 'currency' => 'NGN'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
        $code = $issued->json('code');

        $this->dial('+2342017001724', $token, '')->assertOk();                                  // first step
        $this->dial('+2342017001724', $token, '123456789012#')->assertOk();                     // wrong digits
        $this->dial('+2342017001724', 'wrong-token', $code . '#')->assertForbidden();           // wrong token
        $this->dial('+2347999999999', $token, $code . '#')->assertForbidden();                  // a number nobody registered
        $this->dial('+2342017001724', $token, $code . '#')->assertOk();                         // the right code

        $by = fn (string $outcome) => \App\Models\VoiceCallLog::where('outcome', $outcome)->get();
        $this->assertCount(1, $by('prompt'));
        $this->assertSame('unknown_code', $by('rejected')->first()->reason);
        $this->assertSame(['wrong_token', 'number_not_registered'], \App\Models\VoiceCallLog::where('outcome', 'denied')->orderBy('id')->pluck('reason')->all());
        $this->assertSame($tenant->id, $by('accepted')->first()->tenant_id);
        $this->assertSame(12, $by('accepted')->first()->digits_length);
        $this->assertStringNotContainsString($code, json_encode(\App\Models\VoiceCallLog::all()->toArray()), 'the code itself is never stored');

        $owner = $this->op('finance');
        $this->as($owner)->get('/admin/calls')->assertOk()->assertSee('wrong_token')->assertSee('number_not_registered')->assertSee('No open code matches these digits');
        $this->as($owner)->get('/admin/calls?outcome=accepted')->assertOk()->assertDontSee('wrong_token');
        $this->as($owner)->get('/admin/calls?hide_prompts=1')->assertOk();
    }

    public function test_a_refused_call_from_a_known_payer_tells_the_tenant_once_and_never_carries_the_digits(): void
    {
        Http::fake();
        \Illuminate\Support\Facades\Queue::fake();
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);                                  // sub-1, phone +2348012345678
        $token = $this->makeVoiceNumber('+2342017001724', $tenant);
        app(TenantContext::class)->run($tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.com/h', 'secret' => 'whsec_x']));

        $this->dial('+2342017001724', $token, '123456789012#', '+2348012345678')->assertOk();   // wrong digits, from the payer
        $this->dial('+2342017001724', $token, '123456789013#', '+2348012345678')->assertOk();   // again at once: not sent twice
        $this->dial('+2342017001724', $token, '123456789014#', '+2348099999999')->assertOk();   // a stranger: nobody to tell

        $events = WebhookDelivery::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_type', 'code.rejected')->get();
        $this->assertCount(1, $events);
        $data = $events->first()->payload['data'];
        $this->assertSame(['voice', 'unknown_code', 'sub-1'], [$data['origin'], $data['reason'], $data['subscriber_reference']]);
        $this->assertStringNotContainsString('1234567890', json_encode($events->first()->payload));
    }

    public function test_the_callback_token_can_be_switched_off_temporarily_but_an_unknown_number_is_still_refused(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->makeVoiceNumber('+2342017001724', $tenant);

        // Default: no token, no entry.
        $this->post('/api/voice/africastalking', ['destinationNumber' => '+2342017001724', 'callerNumber' => '+2348012345678', 'sessionId' => 's1', 'isActive' => '1'])->assertForbidden();

        config(['platform.voice.require_token' => false]);
        $this->post('/api/voice/africastalking', ['destinationNumber' => '+2342017001724', 'callerNumber' => '+2348012345678', 'sessionId' => 's2', 'isActive' => '1'])->assertOk()->assertSee('GetDigits', false);
        $this->post('/api/voice/africastalking', ['destinationNumber' => '+2347999999999', 'callerNumber' => '+2348012345678', 'sessionId' => 's3', 'isActive' => '1'])->assertForbidden();

        $owner = $this->op();
        $this->as($owner)->get('/admin/calls')->assertOk()->assertSee('Callback token is switched off');
        $checks = collect(\App\Http\Controllers\Admin\HealthController::checks())->keyBy('label');
        $this->assertSame('fail', $checks['Voice callback token']['status']);
    }
}
