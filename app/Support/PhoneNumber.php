<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Compare two numbers written differently (+234…, 234…, 0…). Matches on
     * the last ten digits, which holds for Nigerian numbers; other
     * numbering plans need a per-tenant rule.
     */
    public static function same(?string $a, ?string $b): bool
    {
        $a = self::tail($a);
        $b = self::tail($b);

        return $a !== '' && $a === $b;
    }

    public static function tail(?string $number): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
