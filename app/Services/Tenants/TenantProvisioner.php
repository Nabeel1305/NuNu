<?php

namespace App\Services\Tenants;

use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Support\Str;

class TenantProvisioner
{
    /**
     * Create a tenant with its first API key. New tenants can start in the
     * sandbox only through the dashboard; going live is a separate, checked step.
     *
     * @return array{0: Tenant, 1: string} the tenant and its plain API key (shown once)
     */
    public function create(string $name, string $voiceMode = 'own', string $environment = 'sandbox'): array
    {
        do {
            $shortCode = (string) random_int(1000, 9999);
        } while (Tenant::where('short_code', $shortCode)->exists());

        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'short_code' => $shortCode,
            'voice_mode' => $voiceMode,
            'environment' => $environment,
            // A live tenant has no simulator; its adapter is set once a real one exists.
            'settlement_adapter' => $environment === 'live' ? 'unconfigured' : 'sandbox',
        ]);

        [, $plain] = ApiKey::issue($tenant, 'default');

        return [$tenant, $plain];
    }
}
