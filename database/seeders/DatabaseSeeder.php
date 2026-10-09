<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Merchant;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\VoiceNumber;
use App\Models\WebhookEndpoint;
use App\Services\Tenants\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Demo data for local development and the simulation. Refuses to run in
 * production. Credentials are random and written to
 * storage/app/simulation.json (git-ignored) so the simulator can use them.
 */
class DatabaseSeeder extends Seeder
{
    private const RECEIVER = 'http://127.0.0.1:9101/hook/';

    public function run(TenantProvisioner $provisioner, TenantContext $context): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('The demo seeder must not run in production.');
        }

        $password = env('SEED_ADMIN_PASSWORD') ?: bin2hex(random_bytes(10));
        Admin::updateOrCreate(['email' => 'ops@example.test'], ['name' => 'Demo Ops', 'password' => $password]);

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

            $credentials['tenants'][$tenant->slug] = [
                'id' => $tenant->id, 'name' => $name, 'api_key' => $key, 'short_code' => $tenant->short_code,
                'voice_mode' => $mode, 'voice_number' => $number, 'voice_token' => $token, 'webhook_secret' => $secret,
                'subscriber_phones' => $context->run($tenant, fn () => Subscriber::orderBy('id')->pluck('phone', 'reference')->all()),
            ];
        }

        $sharedToken = bin2hex(random_bytes(24));
        VoiceNumber::create(['tenant_id' => null, 'number' => '+2347000000099', 'webhook_token_hash' => hash('sha256', $sharedToken)]);
        $credentials['shared_pool'] = ['number' => '+2347000000099', 'token' => $sharedToken];

        File::put(storage_path('app/simulation.json'), json_encode($credentials, JSON_PRETTY_PRINT));

        $this->command?->info('Seeded ' . count($plans) . ' tenants, a shared number and a demo admin (ops@example.test).');
        $this->command?->line('Credentials written to storage/app/simulation.json (git-ignored).');
    }
}
