<?php

namespace App\Support;

class Money
{
    private const SYMBOLS = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'GHS' => 'GH₵', 'KES' => 'KSh '];

    /** 250000 minor units of NGN -> "₦2,500.00". Unknown currencies print their code. */
    public static function format(int|string|null $minor, string $currency = 'NGN'): string
    {
        $minor = (int) $minor;
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency) . ' ';

        return $sign . $symbol . number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "12.50" typed by a person -> 1250, or null when it is not a plain amount. */
    public static function toMinor(?string $major): ?int
    {
        $major = trim((string) $major);
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $major)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $major), 2, '0');

        return (int) $whole * 100 + (int) str_pad($frac, 2, '0');
    }
}
