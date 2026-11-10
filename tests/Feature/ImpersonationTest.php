<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.admin.require_two_factor' => false]);
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 10, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => 'a-long-test-password']);
    }

    private function member(Tenant $tenant, string $role = 'owner', array $extra = []): TenantUser
    {
        static $n = 0;
        $n++;

        return TenantUser::withoutGlobalScopes()->create($extra + [
            'tenant_id' => $tenant->id, 'name' => "Person {$n}", 'email' => "person{$n}@example.com",
            'password' => 'correct-horse-battery', 'role' => $role, 'is_active' => true,
        ]);
    }

    private function start(Admin $admin, Tenant $tenant, TenantUser $user, string $reason = 'Customer cannot see settlements')
    {
        return $this->actingAs($admin, 'admin')->post("/admin/tenants/{$tenant->id}/portal-users/{$user->id}/impersonate", ['reason' => $reason]);
    }

    private function events(Tenant $t, string $type)
    {
        return AuditLog::withoutGlobalScopes()->where('tenant_id', $t->id)->where('event_type', $type)->get();
    }

    public function test_an_operator_can_view_the_portal_as_a_user_and_the_tenant_can_see_it_in_its_audit_log(): void
    {
        [$tenant] = $this->makeTenant();
        $user = $this->member($tenant, 'viewer');

        $this->start($this->admin(), $tenant, $user)->assertRedirect(route('portal.dashboard'));

        $this->get('/portal')->assertOk()->assertSee('Viewing as')->assertSee($user->name)->assertSee('Customer cannot see settlements');
        $started = $this->events($tenant, 'admin.impersonation_started');
        $this->assertCount(1, $started);
        $this->assertSame('admin', $started->first()->actor_type);
        $this->assertStringContainsString('ops@example.test', $started->first()->description);
        $this->assertSame([], app(AuditLogService::class)->verifyChain($tenant->id));
    }

    public function test_a_reason_is_required_and_only_active_accepted_people_of_that_tenant_can_be_viewed(): void
    {
        [$tenant] = $this->makeTenant();
        [$other] = $this->makeTenant();
        $admin = $this->admin();

        $this->start($admin, $tenant, $this->member($tenant), '')->assertSessionHasErrors('reason');
        $this->start($admin, $tenant, $this->member($tenant), 'abc')->assertSessionHasErrors('reason');
        $this->start($admin, $tenant, $this->member($tenant, 'owner', ['is_active' => false]))->assertSessionHasErrors('reason');
        $this->start($admin, $tenant, $this->member($tenant, 'owner', ['password' => null]))->assertSessionHasErrors('reason');
        $this->start($admin, $tenant, $this->member($other))->assertNotFound();
        $this->assertCount(0, $this->events($tenant, 'admin.impersonation_started'));
        $this->assertGuest('tenant');
    }

    public function test_tenant_staff_and_guests_cannot_start_it(): void
    {
        [$tenant] = $this->makeTenant();
        $user = $this->member($tenant);
        $other = $this->member($tenant, 'viewer');

        $this->post("/admin/tenants/{$tenant->id}/portal-users/{$other->id}/impersonate", ['reason' => 'just looking'])->assertRedirect(route('admin.login'));
        $this->actingAs($user, 'tenant')->post("/admin/tenants/{$tenant->id}/portal-users/{$other->id}/impersonate", ['reason' => 'just looking'])->assertRedirect(route('admin.login'));
    }

    public function test_it_is_look_only_and_hides_the_persons_own_security_pages(): void
    {
        [$tenant] = $this->makeTenant(['settings' => ['device_codes' => ['enabled' => true]]]);
        $owner = $this->member($tenant, 'owner');
        $this->start($this->admin(), $tenant, $owner);

        $this->get('/portal/transactions')->assertOk();
        $this->get('/portal/settings')->assertOk();
        $this->get('/portal/account')->assertForbidden();
        $this->post('/portal/developer/keys', ['name' => 'x'])->assertForbidden();
        $this->put('/portal/settings/direct-codes', ['direct_enabled' => '1'])->assertForbidden();
        $this->post('/portal/account/password', ['current_password' => 'correct-horse-battery', 'password' => 'new-long-password-1', 'password_confirmation' => 'new-long-password-1'])->assertForbidden();
        $this->post('/portal/team', ['name' => 'Evil', 'email' => 'evil@example.com', 'role' => 'owner'])->assertForbidden();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('correct-horse-battery', $owner->fresh()->password));
        $this->assertFalse(\App\Models\ApiKey::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'x')->exists());
        $this->assertFalse(TenantUser::withoutGlobalScopes()->where('email', 'evil@example.com')->exists());
    }

    public function test_the_person_s_own_two_factor_is_not_asked_of_the_operator(): void
    {
        config(['platform.admin.require_two_factor' => true]);
        [$tenant] = $this->makeTenant();
        $user = $this->member($tenant);          // no two-factor set up
        $admin = $this->admin();
        $admin->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now()])->save();

        $this->start($admin, $tenant, $user);

        $this->get('/portal')->assertOk()->assertSee('Viewing as');
    }

    public function test_it_ends_by_itself_after_the_time_limit(): void
    {
        [$tenant] = $this->makeTenant();
        $this->start($this->admin(), $tenant, $this->member($tenant));
        $this->get('/portal')->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 11, 10, 10, 31, 0));
        $this->get('/portal')->assertRedirect(route('admin.tenants.show', $tenant->id));

        $this->assertGuest('tenant');
        $this->assertCount(1, $this->events($tenant, 'admin.impersonation_ended'));
        $this->assertStringContainsString('time limit', $this->events($tenant, 'admin.impersonation_ended')->first()->description);
        $this->get('/portal')->assertRedirect(route('portal.login'));
        $this->assertAuthenticated('admin');       // the operator is still signed in
    }

    public function test_stopping_signs_out_only_the_borrowed_identity_and_is_logged(): void
    {
        [$tenant] = $this->makeTenant();
        $this->start($this->admin(), $tenant, $this->member($tenant));

        $this->post('/portal/impersonation/stop')->assertRedirect(route('admin.tenants.show', $tenant->id));

        $this->assertGuest('tenant');
        $this->assertAuthenticated('admin');
        $this->assertCount(1, $this->events($tenant, 'admin.impersonation_ended'));
        $this->get('/portal')->assertRedirect(route('portal.login'));
        $this->get('/admin/tenants')->assertOk();
        $this->assertSame([], app(AuditLogService::class)->verifyChain($tenant->id));
    }

    public function test_it_ends_when_the_operator_signs_out_or_is_switched_off(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->admin();
        $this->start($admin, $tenant, $this->member($tenant));

        $admin->update(['is_active' => false]);
        $this->get('/portal')->assertRedirect(route('portal.login'));
        $this->assertGuest('tenant');
        $this->assertCount(1, $this->events($tenant, 'admin.impersonation_ended'));
    }

    public function test_an_ordinary_portal_session_is_untouched(): void
    {
        [$tenant] = $this->makeTenant();
        $user = $this->member($tenant);

        $this->actingAs($user, 'tenant')->get('/portal')->assertOk()->assertDontSee('Viewing as');
        $this->post('/portal/impersonation/stop')->assertRedirect(route('portal.login'));
    }

    public function test_the_operator_console_offers_view_as_only_for_people_who_can_sign_in(): void
    {
        [$tenant] = $this->makeTenant();
        $this->member($tenant, 'owner');
        $this->member($tenant, 'viewer', ['is_active' => false]);

        $html = $this->actingAs($this->admin(), 'admin')->get("/admin/tenants/{$tenant->id}")->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '/impersonate'));
    }
}
