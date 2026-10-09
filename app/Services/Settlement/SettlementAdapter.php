<?php

namespace App\Services\Settlement;

use App\Models\Tenant;

/**
 * What the engine needs from a tenant's core system. The tenant's real API
 * is not available yet, so this is the contract each adapter must meet. The
 * engine instructs; the tenant's system moves the money.
 */
interface SettlementAdapter
{
    /** Reserve funds on the payer's account when a code is issued. */
    public function hold(Tenant $tenant, string $reference, string $accountReference, int $amountMinor, string $currency): SettlementResult;

    /** Move held funds to the merchant's account once a call is verified. */
    public function capture(Tenant $tenant, string $holdReference, string $merchantAccountReference, string $transactionReference): SettlementResult;

    /** Give held funds back after a cancel, expiry or failed capture. */
    public function release(Tenant $tenant, string $holdReference): SettlementResult;

    /**
     * What happened to the capture for this transaction reference. Used to
     * resolve a capture call that never reported back, so an adapter must
     * answer from the tenant's own records and say 'unknown' when it cannot.
     * capture() must also be safe to repeat for the same transaction reference.
     */
    public function status(Tenant $tenant, string $transactionReference): SettlementStatus;
}
