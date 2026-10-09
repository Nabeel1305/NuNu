<?php

namespace App\Services\Codes;

enum CodeState: string
{
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Issued => [self::Redeemed, self::Cancelled, self::Expired],
            self::Redeemed => [self::Settled, self::Failed],
            default => [],
        };
    }

    public function canTransitionTo(self $other): bool
    {
        return in_array($other, $this->next(), true);
    }

    public function isTerminal(): bool
    {
        return $this->next() === [];
    }
}
