<?php

namespace App\Services\Settlement;

final class SettlementStatus
{
    public const STATES = ['held', 'captured', 'released', 'unknown'];

    /** @param 'held'|'captured'|'released'|'unknown' $state */
    public function __construct(public readonly string $state, public readonly ?string $reference = null)
    {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException("Unknown settlement state '{$state}'.");
        }
    }
}
