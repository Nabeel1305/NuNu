<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * The tenant the current request, call or job is acting for. Tenant-owned
 * models read this to scope every query and to stamp tenant_id on create.
 * It is set in exactly three places: the API key middleware, the voice
 * gateway once it has resolved the call, and queued jobs for one tenant.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /** Run a callback as a tenant, then restore whatever context was set before. */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
