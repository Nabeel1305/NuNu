<?php

namespace App\Support;

class Mask
{
    /** +2348012345678 -> +23480••••78: enough to recognise, not enough to call. */
    public static function phone(?string $phone): string
    {
        $phone = (string) $phone;
        $len = strlen($phone);
        if ($len < 9) {
            return $phone === '' ? '—' : str_repeat('•', $len);
        }

        return substr($phone, 0, 6) . '••••' . substr($phone, -2);
    }

    /** Spreadsheet programs run cells that start with = + - @; neutralise them in exports. */
    public static function csv(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
