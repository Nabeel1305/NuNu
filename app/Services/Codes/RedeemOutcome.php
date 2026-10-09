<?php

namespace App\Services\Codes;

use App\Models\PaymentCode;

final class RedeemOutcome
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $reason = null,
        public readonly ?PaymentCode $code = null,
    ) {
    }

    public static function accepted(PaymentCode $code): self
    {
        return new self(true, null, $code);
    }

    /** The reason is for logs and tests only; callers never hear it. */
    public static function rejected(string $reason): self
    {
        return new self(false, $reason);
    }
}
