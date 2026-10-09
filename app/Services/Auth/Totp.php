<?php

namespace App\Services\Auth;

/**
 * Time-based one-time passwords, RFC 6238 (HMAC-SHA1, 30-second steps, 6 digits):
 * the format every authenticator app understands.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hmac[19]) & 0x0F;
        $value = ((ord($hmac[$offset]) & 0x7F) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code against the current step and one either side (for clock drift).
     * A step at or before $lastStep is refused, so a code that was already used,
     * or one older than the last one used, cannot be replayed.
     *
     * @return int|null the matching step (store it as the new last step), or null
     */
    public static function verify(string $secret, string $code, int $timestamp, ?int $lastStep = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        $now = self::step($timestamp);
        $matched = null;

        // Check every step in the window (no early exit) so timing does not reveal which one matched.
        for ($i = -$window; $i <= $window; $i++) {
            $step = $now + $i;

            if (hash_equals(self::code($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $char) {
            $pos = strpos(self::ALPHABET, $char);

            if ($pos === false) {
                throw new \InvalidArgumentException('Not a base32 string.');
            }

            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
