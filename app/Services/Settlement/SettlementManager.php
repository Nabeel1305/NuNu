<?php

namespace App\Services\Settlement;

use App\Models\Tenant;

class SettlementManager
{
    /** Adapters that exist. The dashboard offers only these. */
    public const ADAPTERS = ['sandbox'];

    public function for(Tenant $tenant): SettlementAdapter
    {
        // Fail closed: a live tenant must never be settled by the simulator.
        if ($tenant->settlement_adapter === 'sandbox' && $tenant->isLive()) {
            throw new \RuntimeException("Live tenant {$tenant->slug} is configured with the sandbox adapter.");
        }

        return match ($tenant->settlement_adapter) {
            'sandbox' => app(SandboxAdapter::class),
            default => throw new \RuntimeException("Unknown settlement adapter '{$tenant->settlement_adapter}'."),
        };
    }
}
