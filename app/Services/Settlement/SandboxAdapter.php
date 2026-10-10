<?php

namespace App\Services\Settlement;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Simulates a core system for development and sandbox tenants. Nothing is
 * stored; tenant settings `sandbox.fail_hold` and `sandbox.fail_capture` make
 * the matching call fail, so failure paths can be exercised end to end.
 */
class SandboxAdapter implements SettlementAdapter
{
    public function hold(Tenant $tenant, string $reference, AccountRef $source, int $amountMinor, string $currency): SettlementResult
    {
        if ($tenant->setting('sandbox.fail_hold')) {
            return SettlementResult::rejected('Insufficient funds (sandbox).');
        }

        return SettlementResult::ok('hold_' . Str::uuid());
    }

    public function capture(Tenant $tenant, string $holdReference, AccountRef $destination, string $transactionReference): SettlementResult
    {
        if ($tenant->setting('sandbox.fail_capture')) {
            return SettlementResult::rejected('Capture declined (sandbox).');
        }

        return SettlementResult::ok('settle_' . Str::uuid());
    }

    public function release(Tenant $tenant, string $holdReference): SettlementResult
    {
        return SettlementResult::ok($holdReference);
    }

    public function status(Tenant $tenant, string $transactionReference): SettlementStatus
    {
        // `sandbox.status` lets tests and demos choose the answer.
        return new SettlementStatus(
            (string) $tenant->setting('sandbox.status', 'unknown'),
            $tenant->setting('sandbox.status') === 'captured' ? 'settle_' . $transactionReference : null,
        );
    }
}
