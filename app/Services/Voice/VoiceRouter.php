<?php

namespace App\Services\Voice;

use App\Models\Tenant;
use App\Models\VoiceNumber;

class VoiceRouter
{
    /**
     * Decide which tenant a call belongs to. A tenant-owned number maps
     * straight to its tenant. On the shared pool the first four digits keyed
     * in are the tenant's short code. Returns null when no active tenant fits.
     */
    public function tenantFor(VoiceNumber $number, ?string $digits): ?Tenant
    {
        if (! $number->isSharedPool()) {
            $tenant = $number->tenant;
        } else {
            $prefix = substr((string) $digits, 0, 4);
            $tenant = strlen($prefix) === 4
                ? Tenant::where('short_code', $prefix)->where('voice_mode', 'shared')->first()
                : null;
        }

        return $tenant && $tenant->isActive() ? $tenant : null;
    }
}
