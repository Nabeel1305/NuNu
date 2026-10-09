<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ApiKey;
use App\Models\PaymentCode;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => 'a-long-test-password']);
    }

    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'status' => 'active', 'environment' => 'sandbox', 'settlement_adapter' => 'sandbox',
            'voice_mode' => 'own', 'code_ttl_minutes' => 10,
        ];
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin/tenants')->assertRedirect('/admin/login');
    }

    public function test_login_failures_all_look_the_same(): void
    {
        $this->admin();

        $wrongPassword = $this->from('/admin/login')->post('/admin/login', ['email' => 'ops@example.test', 'password' => 'nope']);
        $unknownEmail = $this->from('/admin/login')->post('/admin/login', ['email' => 'who@example.test', 'password' => 'nope']);

        $this->assertSame(
            $wrongPassword->baseResponse->getSession()->get('errors')->get('email'),
            $unknownEmail->baseResponse->getSession()->get('errors')->get('email'),
        );
        $this->assertGuest('admin');
    }

    public function test_an_inactive_admin_cannot_sign_in(): void
    {
        $admin = $this->admin();
        $admin->update(['is_active' => false]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'a-long-test-password']);

        $this->assertGuest('admin');
    }

    public function test_repeated_failures_lock_the_login_for_a_while(): void
    {
        $this->admin();

        foreach (range(1, 5) as $i) {
            $this->post('/admin/login', ['email' => 'ops@example.test', 'password' => 'bad']);
        }

        $this->post('/admin/login', ['email' => 'ops@example.test', 'password' => 'a-long-test-password']);

        $this->assertGuest('admin');
    }

    public function test_an_admin_can_create_a_tenant_and_sees_its_key_once(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/tenants', ['name' => 'Acme Bank', 'voice_mode' => 'own'])
            ->assertRedirect();

        $key = session('secret')['value'];

        $this->assertMatchesRegularExpression('/^opk_[0-9a-f]{8}_[0-9a-f]{40}$/', $key);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $key]);
        $this->assertDatabaseHas('api_keys', ['key_hash' => hash('sha256', $key)]);
    }

    public function test_a_live_tenant_cannot_use_the_sandbox_adapter(): void
    {
        [$tenant] = $this->makeTenant();

        $this->actingAs($this->admin(), 'admin')
            ->put("/admin/tenants/{$tenant->id}", $this->settings(['environment' => 'live']))
            ->assertSessionHasErrors('environment');

        $this->assertSame('sandbox', $tenant->fresh()->environment);
    }

    public function test_the_voice_mode_cannot_change_while_codes_are_open(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);
        $this->issueCode($key)->assertCreated();

        $this->actingAs($this->admin(), 'admin')
            ->put("/admin/tenants/{$tenant->id}", $this->settings(['voice_mode' => 'shared']))
            ->assertSessionHasErrors('voice_mode');

        $this->assertSame(1, PaymentCode::withoutGlobalScopes()->count());
    }

    public function test_suspending_a_tenant_stops_its_api_access(): void
    {
        [$tenant, $key] = $this->makeTenant();

        $this->actingAs($this->admin(), 'admin')
            ->put("/admin/tenants/{$tenant->id}", $this->settings(['status' => 'suspended']))
            ->assertSessionHasNoErrors();

        $this->withToken($key)->getJson('/api/v1/codes/x')->assertForbidden();
    }

    public function test_a_revoked_key_stops_working_and_other_tenants_keys_cannot_be_revoked(): void
    {
        [$a, $keyA] = $this->makeTenant();
        [$b] = $this->makeTenant();
        $admin = $this->admin();

        $keyRow = ApiKey::where('tenant_id', $a->id)->first();

        $this->actingAs($admin, 'admin')->delete("/admin/tenants/{$b->id}/keys/{$keyRow->id}")->assertNotFound();
        $this->withToken($keyA)->getJson('/api/v1/codes/x')->assertNotFound();

        $this->actingAs($admin, 'admin')->delete("/admin/tenants/{$a->id}/keys/{$keyRow->id}", ['confirm' => '1'])->assertRedirect();
        $this->withToken($keyA)->getJson('/api/v1/codes/x')->assertUnauthorized();
    }

    public function test_admin_actions_are_written_to_the_tenant_audit_chain(): void
    {
        [$tenant] = $this->makeTenant();
        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/tenants/{$tenant->id}/keys", ['name' => 'second'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'event_type' => 'admin.key_issued', 'actor_type' => 'admin']);
        $this->assertSame([], app(AuditLogService::class)->verifyChain($tenant->id));
    }

    public function test_revoking_a_key_needs_the_confirmation_box(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $keyRow = ApiKey::where('tenant_id', $tenant->id)->first();

        $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/tenants/{$tenant->id}/keys/{$keyRow->id}")
            ->assertSessionHasErrors('confirm');

        $this->assertNull($keyRow->fresh()->revoked_at);
    }

    public function test_responses_carry_security_headers(): void
    {
        $this->get('/admin/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');

        $this->getJson('/api/v1/codes/x')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('Content-Security-Policy');
    }
}
