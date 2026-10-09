<?php

namespace App\Services\Settlement;

final class SettlementResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reference = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function ok(string $reference): self
    {
        return new self(true, $reference);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
