<?php

namespace App\Support;

class PhoneNumber
{
    /** Country code assumed for numbers written without one (0801…, or 10 national digits). Nigeria by default. */
    public static string $defaultCountryCode = '234';

    /**
     * Digits only, with the country code always present: +234 801…, 0801… and 801… (national)
     * all become 234801…. Two numbers are the same only when these full forms are equal, so a
     * number from another country can never match one that merely ends in the same digits.
     */
    public static function normalize(?string $number): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';
        if ($digits === '') {
            return '';
        }

        $cc = self::$defaultCountryCode;

        if (str_starts_with($digits, '00')) {                // 00234… international dialling prefix
            return substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {                 // 0801… local trunk prefix
            return $cc . ltrim($digits, '0');
        }
        if (strlen($digits) === 10 && ! str_starts_with($digits, $cc)) {   // 801… national, no prefix
            return $cc . $digits;
        }

        return $digits;
    }

    /** Compare two numbers written differently (+234…, 234…, 0…). Full numbers must be equal. */
    public static function same(?string $a, ?string $b): bool
    {
        $a = self::normalize($a);

        return $a !== '' && $a === self::normalize($b);
    }

    /** Kept for callers that only need a short stable key for a number. */
    public static function tail(?string $number): string
    {
        $digits = self::normalize($number);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
