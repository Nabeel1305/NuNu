<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-test-password';

    private function admin(bool $twoFactor = true): Admin
    {
        $admin = Admin::create(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => self::PASSWORD]);

        if ($twoFactor) {
            $admin->forceFill(['totp_secret' => Totp::generateSecret(), 'totp_confirmed_at' => now()])->save();
        }

        return $admin->fresh();
    }

    private function codeFor(Admin $admin, int $stepOffset = 0): string
    {
        return Totp::code($admin->totp_secret, Totp::step(now()->timestamp) + $stepOffset);
    }

    private function password(): \Illuminate\Testing\TestResponse
    {
        return $this->post('/admin/login', ['email' => 'ops@example.test', 'password' => self::PASSWORD]);
    }

    public function test_a_correct_password_alone_does_not_sign_in_an_admin_with_two_factor(): void
    {
        $this->admin();

        $this->password()->assertRedirect('/admin/two-factor');

        $this->assertGuest('admin');
        $this->get('/admin/tenants')->assertRedirect('/admin/login');
    }

    public function test_the_right_code_completes_sign_in(): void
    {
        $admin = $this->admin();
        $this->password();

        $this->post('/admin/two-factor', ['code' => $this->codeFor($admin)])->assertRedirect('/admin/tenants');

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_wrong_code_does_not(): void
    {
        $this->admin();
        $this->password();

        $this->post('/admin/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest('admin');
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $admin = $this->admin();
        $code = $this->codeFor($admin);

        $this->password();
        $this->post('/admin/two-factor', ['code' => $code])->assertRedirect('/admin/tenants');
        $this->post('/admin/logout');

        $this->password();
        $this->post('/admin/two-factor', ['code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest('admin');
    }

    public function test_five_wrong_codes_end_the_attempt_even_for_the_right_one(): void
    {
        $admin = $this->admin();
        $this->password();

        foreach (range(1, 5) as $i) {
            $this->post('/admin/two-factor', ['code' => '00000' . $i]);
        }

        $this->post('/admin/two-factor', ['code' => $this->codeFor($admin)])->assertRedirect();

        $this->assertGuest('admin');
    }

    public function test_the_pending_sign_in_expires_after_five_minutes(): void
    {
        $admin = $this->admin();
        $this->password();

        $this->travel(6)->minutes();

        $this->post('/admin/two-factor', ['code' => $this->codeFor($admin)])->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    public function test_the_challenge_page_is_not_reachable_without_a_password_first(): void
    {
        $this->get('/admin/two-factor')->assertRedirect('/admin/login');
    }

    public function test_an_admin_can_set_up_an_authenticator_and_must_prove_it_works(): void
    {
        $admin = $this->admin(false);
        $this->actingAs($admin, 'admin');

        $this->get('/admin/security')->assertOk()->assertSee('Enter this key by hand', false);

        $admin = $admin->fresh();
        $this->assertNotNull($admin->totp_secret);
        $this->assertFalse($admin->hasTwoFactor());

        $this->post('/admin/security', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($admin->fresh()->hasTwoFactor());

        $this->post('/admin/security', ['code' => $this->codeFor($admin)])->assertRedirect('/admin/security');
        $this->assertTrue($admin->fresh()->hasTwoFactor());
    }

    public function test_the_secret_is_stored_encrypted_and_never_shown_once_enabled(): void
    {
        $admin = $this->admin();

        $raw = \Illuminate\Support\Facades\DB::table('admins')->where('id', $admin->id)->value('totp_secret');
        $this->assertNotSame($admin->totp_secret, $raw);

        $this->actingAs($admin, 'admin')->get('/admin/security')->assertOk()->assertDontSee($admin->totp_secret);
    }

    public function test_when_required_an_admin_without_two_factor_can_only_reach_setup(): void
    {
        config(['platform.admin.require_two_factor' => true]);
        $admin = $this->admin(false);
        $this->actingAs($admin, 'admin');

        $this->get('/admin/tenants')->assertRedirect('/admin/security');
        $this->get('/admin/security')->assertOk();
    }

    public function test_when_not_required_an_admin_without_two_factor_can_use_the_dashboard(): void
    {
        config(['platform.admin.require_two_factor' => false]);

        $this->actingAs($this->admin(false), 'admin')->get('/admin/tenants')->assertOk();
    }

    public function test_two_factor_cannot_be_switched_off_from_the_dashboard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->delete('/admin/security')->assertStatus(405);
        $this->actingAs($admin, 'admin')->post('/admin/security', ['code' => $this->codeFor($admin)])->assertRedirect('/admin/security');

        $this->assertTrue($admin->fresh()->hasTwoFactor());
    }

    public function test_the_reset_command_removes_it_and_ends_the_admins_sessions(): void
    {
        $admin = $this->admin();
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 's1', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('admin:reset-2fa', ['email' => $admin->email])->assertSuccessful();

        $this->assertFalse($admin->fresh()->hasTwoFactor());
        $this->assertNull($admin->fresh()->totp_secret);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $admin->id)->count());
    }

    public function test_an_unknown_email_gets_the_same_answer_as_a_wrong_password(): void
    {
        $this->admin();

        $unknown = $this->from('/admin/login')->post('/admin/login', ['email' => 'who@example.test', 'password' => 'whatever']);
        $wrong = $this->from('/admin/login')->post('/admin/login', ['email' => 'ops@example.test', 'password' => 'whatever']);

        $this->assertSame(
            $unknown->baseResponse->getSession()->get('errors')->get('email'),
            $wrong->baseResponse->getSession()->get('errors')->get('email'),
        );
    }
}
