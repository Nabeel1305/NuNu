<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentCode;
use App\Models\Subscriber;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Services\Auth\Totp;
use App\Services\Codes\CodeService;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Regression tests for holes found in a security review. Each asserts the SECURE behaviour. */
class SecurityRegressionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.admin.require_two_factor' => false]);
    }

    private function user($tenant, array $extra = []): TenantUser
    {
        static $n = 0;
        $n++;

        return TenantUser::withoutGlobalScopes()->create($extra + ['tenant_id' => $tenant->id, 'name' => "U{$n}", 'email' => "u{$n}@example.com", 'password' => 'correct-horse-battery', 'role' => 'owner', 'is_active' => true]);
    }

    public function test_A_an_access_link_does_not_skip_the_second_factor(): void
    {
        [$tenant] = $this->makeTenant();
        $victim = $this->user($tenant, ['totp_secret' => Totp::generateSecret(), 'totp_confirmed_at' => now()]);
        $link = TenantInvitation::issue($victim);   // e.g. a leaked or intercepted access link

        $this->post("/portal/invite/{$link}", ['name' => 'Attacker', 'password' => 'attacker-chosen-pass', 'password_confirmation' => 'attacker-chosen-pass']);

        // The link alone is not enough: the second factor is still required.
        $this->assertGuest('tenant');
        $this->assertNotNull(session('tenant_2fa'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('attacker-chosen-pass', $victim->fresh()->password));
        $this->post('/portal/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('tenant');
    }

    public function test_B_a_switched_off_user_cannot_switch_themselves_back_on_with_an_old_link(): void
    {
        [$tenant] = $this->makeTenant();
        $u = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Leaver', 'email' => 'leaver@example.com', 'role' => 'owner', 'is_active' => true]);
        $link = TenantInvitation::issue($u);
        $u->update(['is_active' => false]);                          // an owner switches them off

        $this->post("/portal/invite/{$link}", ['name' => 'Leaver', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password']);

        $this->assertFalse($u->fresh()->is_active);
        $this->assertGuest('tenant');
        $this->get("/portal/invite/{$link}")->assertNotFound();
    }

    public function test_B2_switching_someone_off_voids_their_open_links(): void
    {
        [$tenant] = $this->makeTenant();
        $owner = $this->user($tenant);
        $u = TenantUser::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Leaver', 'email' => 'leaver2@example.com', 'role' => 'viewer', 'is_active' => true]);
        $link = TenantInvitation::issue($u);

        $this->actingAs($owner, 'tenant')->patch("/portal/team/{$u->id}")->assertRedirect();

        $this->assertSame(0, TenantInvitation::withoutGlobalScopes()->where('tenant_user_id', $u->id)->count());
        $this->get("/portal/invite/{$link}")->assertNotFound();
    }

    public function test_C_spoofed_callers_cannot_use_up_the_budget_real_payers_depend_on(): void
    {
        [$tenant, $key] = $this->makeTenant();
        $this->seedParties($tenant);                       // sub-1 is registered with +2348012345678
        $plain = $this->issueCode($key)->json('code');

        $codes = app(CodeService::class);
        $limited = 0;
        foreach (range(1, 130) as $i) {                    // junk guesses, each "from" a different made-up number
            $r = $codes->redeem($tenant, '9' . str_pad((string) $i, 11, '0', STR_PAD_LEFT), '+23480000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT));
            $limited += $r->reason === 'rate_limited' ? 1 : 0;
        }
        $this->assertGreaterThan(0, $limited);             // the flood itself is throttled…

        $real = $codes->redeem($tenant, $plain, '+2348012345678');
        $this->assertTrue($real->accepted);                // …but the registered payer is untouched
    }

    public function test_C2_an_unregistered_caller_is_still_bounded_by_the_small_shared_budget(): void
    {
        [$tenant] = $this->makeTenant();
        $this->seedParties($tenant);
        config(['platform.redeem.unknown_caller_per_minute' => 5]);

        $codes = app(CodeService::class);
        foreach (range(1, 5) as $i) {
            $this->assertSame('unknown_code', $codes->redeem($tenant, '1000000000' . $i . '0', '+2348090000' . $i . '00')->reason);
        }
        $this->assertSame('rate_limited', $codes->redeem($tenant, '10000000000', '+23480900099')->reason);
    }

    public function test_D_caller_binding_does_not_match_numbers_from_other_countries(): void
    {
        $this->assertFalse(PhoneNumber::same('+2348012345678', '+18012345678'));   // same last 10 digits, different country
        $this->assertTrue(PhoneNumber::same('+2348012345678', '08012345678'));
        $this->assertTrue(PhoneNumber::same('2348012345678', '+234 801 234 5678'));
        $this->assertTrue(PhoneNumber::same('+2348012345678', '8012345678'));
        $this->assertTrue(PhoneNumber::same('+2348012345678', '002348012345678'));
    }

    public function test_E_guessing_spread_over_many_addresses_locks_the_account(): void
    {
        config(['platform.login.account_max_failures' => 12]);
        [$tenant] = $this->makeTenant();
        $u = $this->user($tenant);

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            foreach (range(1, 5) as $_) {
                $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/portal/login', ['email' => $u->email, 'password' => 'wrong-password-' . $ip])->assertSessionHasErrors('email');
            }
        }

        // A fresh address, and the right password: still refused while the account is locked.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->post('/portal/login', ['email' => $u->email, 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('tenant');
    }

    public function test_E2_authenticator_guessing_spread_over_addresses_locks_the_second_step(): void
    {
        config(['platform.login.second_factor_max_failures' => 3]);
        [$tenant] = $this->makeTenant();
        $secret = Totp::generateSecret();
        $u = $this->user($tenant, ['totp_secret' => $secret, 'totp_confirmed_at' => now()]);

        $this->post('/portal/login', ['email' => $u->email, 'password' => 'correct-horse-battery'])->assertRedirect(route('portal.two-factor'));
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/portal/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        // Even the correct code, from yet another address, no longer gets in.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->post('/portal/two-factor', ['code' => Totp::code($secret, Totp::step(now()->timestamp))]);
        $this->assertGuest('tenant');
    }

    public function test_F_the_last_owner_cannot_be_removed_even_when_the_request_names_a_stale_role(): void
    {
        [$tenant] = $this->makeTenant();
        $a = $this->user($tenant);
        $b = $this->user($tenant);

        $this->actingAs($a, 'tenant')->delete("/portal/team/{$b->id}")->assertRedirect();   // fine: a remains
        $c = $this->user($tenant);
        $this->actingAs($c, 'tenant')->delete("/portal/team/{$a->id}")->assertRedirect();   // fine: c remains

        $this->assertSame(1, TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->count());
    }

    public function test_G_webhook_addresses_in_carrier_grade_nat_and_similar_ranges_are_refused(): void
    {
        config(['platform.webhooks.allow_private_urls' => false]);
        foreach (['100.64.0.1', '100.127.255.254', '198.18.0.5', '192.0.0.8', '224.0.0.1'] as $ip) {
            try {
                (new \App\Services\Webhooks\UrlGuard())->assertPublic("https://{$ip}/hook");
                $this->fail("{$ip} should have been refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('public address', $e->getMessage());
            }
        }
        $this->assertSame(['8.8.8.8'], (new \App\Services\Webhooks\UrlGuard())->assertPublic('https://8.8.8.8/hook'));
    }

    public function test_H_issuing_many_keys_never_collides_on_the_prefix(): void
    {
        [$tenant] = $this->makeTenant();
        foreach (range(1, 60) as $i) {
            \App\Models\ApiKey::issue($tenant, "k{$i}");
        }
        $prefixes = \App\Models\ApiKey::where('tenant_id', $tenant->id)->pluck('prefix');
        $this->assertSame($prefixes->count(), $prefixes->unique()->count());
    }

    public function test_J_overlong_references_are_a_validation_error_not_a_server_error(): void
    {
        [, $key] = $this->makeTenant();
        $long = str_repeat('a', 300);

        $this->withToken($key)->putJson("/api/v1/subscribers/{$long}", ['account_number' => '2000000001', 'bank_code' => '058'])->assertStatus(422);
        $this->withToken($key)->putJson("/api/v1/merchants/{$long}", ['name' => 'x', 'account_number' => '3000000001', 'bank_code' => '011'])->assertStatus(422);
    }
}
