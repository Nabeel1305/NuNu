<?php

namespace App\Services\Settlement;

/** A bank account as the platform hands it to a settlement adapter: number and bank code, plus the tenant's own reference if it gave one. */
final class AccountRef
{
    public function __construct(
        public readonly string $number,
        public readonly string $bankCode,
        public readonly ?string $reference = null,
    ) {
    }
}
