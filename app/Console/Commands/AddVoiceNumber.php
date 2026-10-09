<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\VoiceNumber;
use Illuminate\Console\Command;

class AddVoiceNumber extends Command
{
    protected $signature = 'voice-number:add {number : E.164, e.g. +2347000000000} {--tenant= : tenant slug; omit for the shared pool} {--provider=africastalking}';

    protected $description = 'Register a voice number and print its callback token (shown once).';

    public function handle(): int
    {
        $tenantId = null;

        if ($slug = $this->option('tenant')) {
            $tenant = Tenant::where('slug', $slug)->first();

            if (! $tenant) {
                $this->error("No tenant with slug {$slug}.");

                return self::FAILURE;
            }

            $tenantId = $tenant->id;
        }

        $token = bin2hex(random_bytes(24));

        VoiceNumber::create([
            'tenant_id' => $tenantId,
            'provider' => $this->option('provider'),
            'number' => preg_replace('/[^\d+]/', '', $this->argument('number')),
            'webhook_token_hash' => hash('sha256', $token),
        ]);

        $this->info($tenantId ? 'Number registered for the tenant.' : 'Number registered in the shared pool.');
        $this->line("Callback URL (token shown once): " . url('/api/voice/africastalking') . "?token={$token}");

        return self::SUCCESS;
    }
}
