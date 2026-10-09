<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Merchant;
use App\Models\PaymentCode;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Transaction;
use App\Models\VoiceNumber;
use App\Models\WebhookEndpoint;
use App\Services\Codes\CodeState;
use App\Services\Tenants\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Demo data for local development and the simulation. Refuses to run in
 * production. API keys and secrets are random and written to
 * storage/app/simulation.json (git-ignored) so the simulator can use them.
 *
 * Each demo tenant also gets portal logins (owner, developer, viewer) so /portal can be tried
 * straight away. The admin and portal passwords are both "password" unless SEED_ADMIN_PASSWORD or
 * SEED_PORTAL_PASSWORD is set; this is demo data and the seeder refuses to run in production. Set SEED_DEMO_HISTORY=1 to also fill each tenant with a fortnight of sample
 * payments; it is off by default because the simulation counts what it creates itself.
 */
class DatabaseSeeder extends Seeder
{
    private const RECEIVER = 'http://127.0.0.1:9101/hook/';

    public function run(TenantProvisioner $provisioner, TenantContext $context): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('The demo seeder must not run in production.');
        }

        $password = env('SEED_ADMIN_PASSWORD') ?: 'password';
        Admin::updateOrCreate(['email' => 'ops@example.test'], ['name' => 'Demo Ops', 'password' => $password]);

        $portalPassword = env('SEED_PORTAL_PASSWORD') ?: 'password';
        $withHistory = filter_var(env('SEED_DEMO_HISTORY'), FILTER_VALIDATE_BOOL);

        $credentials = ['admin' => ['email' => 'ops@example.test', 'password' => $password], 'tenants' => []];

        $plans = [
            // name, voice mode, settings, own voice number (null = shared pool), bind caller
            ['Demo Bank', 'own', [], '+2347000000001', true],
            ['Demo Fintech', 'shared', [], null, false],
            ['Demo Wallet', 'shared', [], null, false],
            ['Declining Bank', 'own', ['sandbox' => ['fail_capture' => true]], '+2347000000002', false],
        ];

        foreach ($plans as $i => [$name, $mode, $settings, $number, $bind]) {
            [$tenant, $key] = $provisioner->create($name, $mode);
            $tenant->update(['settings' => $settings, 'bind_caller' => $bind]);

            $context->run($tenant, function () use ($tenant, $i) {
                foreach (range(1, 5) as $n) {
                    Subscriber::create(['reference' => "sub-{$n}", 'phone' => '+23480' . ($i + 1) . str_pad((string) $n, 7, '0', STR_PAD_LEFT)]);
                }

                foreach (['shop-1' => 'Corner Shop', 'shop-2' => 'Fuel Station'] as $ref => $shop) {
                    Merchant::create(['reference' => $ref, 'name' => $shop, 'account_reference' => "acct-{$tenant->slug}-{$ref}"]);
                }
            });

            $secret = 'whsec_' . bin2hex(random_bytes(24));
            $context->run($tenant, fn () => WebhookEndpoint::create(['url' => self::RECEIVER . $tenant->slug, 'secret' => $secret]));

            $token = null;
            if ($number) {
                $token = bin2hex(random_bytes(24));
                VoiceNumber::create(['tenant_id' => $tenant->id, 'number' => $number, 'webhook_token_hash' => hash('sha256', $token)]);
            }

            $portalUsers = [];
            foreach (['owner', 'developer', 'viewer'] as $role) {
                $email = "{$role}@{$tenant->slug}.example.test";
                TenantUser::withoutGlobalScopes()->updateOrCreate(['email' => $email], [
                    'tenant_id' => $tenant->id, 'name' => ucfirst($role) . ' (' . $name . ')', 'role' => $role,
                    'password' => $portalPassword, 'is_active' => true,
                ]);
                $portalUsers[$role] = ['email' => $email, 'password' => $portalPassword];
            }

            if ($withHistory) {
                $this->history($tenant, $context);
            }

            $credentials['tenants'][$tenant->slug] = [
                'id' => $tenant->id, 'name' => $name, 'api_key' => $key, 'short_code' => $tenant->short_code,
                'voice_mode' => $mode, 'voice_number' => $number, 'voice_token' => $token, 'webhook_secret' => $secret,
                'subscriber_phones' => $context->run($tenant, fn () => Subscriber::orderBy('id')->pluck('phone', 'reference')->all()),
                'portal_users' => $portalUsers,
            ];
        }

        $sharedToken = bin2hex(random_bytes(24));
        VoiceNumber::create(['tenant_id' => null, 'number' => '+2347000000099', 'webhook_token_hash' => hash('sha256', $sharedToken)]);
        $credentials['shared_pool'] = ['number' => '+2347000000099', 'token' => $sharedToken];

        File::put(storage_path('app/simulation.json'), json_encode($credentials, JSON_PRETTY_PRINT));

        $this->command?->info('Seeded ' . count($plans) . ' tenants, a shared number and a demo admin (ops@example.test).');
        $this->command?->line('Portal logins: owner@, developer@ and viewer@<tenant-slug>.example.test at /portal' . ($withHistory ? ', with sample payments.' : '.'));
        $this->command?->line('Credentials written to storage/app/simulation.json (git-ignored).');
    }

    /**
     * A fortnight of sample payments so the portal has something to show. Written straight to the
     * tables (no API calls, no webhooks), so it makes no audit entries and settles nothing.
     */
    private function history(Tenant $tenant, TenantContext $context): void
    {
        $context->run($tenant, function () {
            $subscribers = Subscriber::orderBy('id')->get();
            $merchants = Merchant::orderBy('id')->get();
            $outcomes = ['settled', 'settled', 'settled', 'settled', 'settled', 'failed', 'settled', 'pending'];

            for ($i = 0; $i < 28; $i++) {
                $status = $outcomes[$i % count($outcomes)];
                $amount = [50000, 120000, 250000, 480000, 1500000][$i % 5];
                $at = now()->subDays(intdiv($i, 2))->subMinutes($i * 37);

                $code = PaymentCode::create([
                    'uuid' => (string) Str::uuid(),
                    'subscriber_id' => $subscribers[$i % $subscribers->count()]->id,
                    'merchant_id' => $merchants[$i % $merchants->count()]->id,
                    'amount_minor' => $amount, 'currency' => 'NGN',
                    'source_account_reference' => 'acct-demo-' . ($i % 5 + 1),
                    'code_hash' => hash('sha256', Str::random(24)),
                    'state' => match ($status) { 'settled' => CodeState::Settled, 'failed' => CodeState::Failed, default => CodeState::Redeemed },
                    'expires_at' => $at->copy()->addMinutes(5), 'redeemed_at' => $at->copy()->addMinute(),
                    'caller_number' => $subscribers[$i % $subscribers->count()]->phone,
                    'created_at' => $at, 'updated_at' => $at,
                ]);

                Transaction::create([
                    'uuid' => (string) Str::uuid(), 'payment_code_id' => $code->id,
                    'reference' => 'DEMO-' . strtoupper(Str::random(8)),
                    'amount_minor' => $amount, 'currency' => 'NGN', 'status' => $status,
                    'settlement_reference' => $status === 'settled' ? 'SBX-' . strtoupper(Str::random(8)) : null,
                    'failure_reason' => $status === 'failed' ? 'Insufficient funds' : null,
                    'settled_at' => $status === 'settled' ? $at->copy()->addMinutes(2) : null,
                    'created_at' => $at->copy()->addMinute(), 'updated_at' => $at->copy()->addMinute(),
                ]);
            }
        });
    }
}
