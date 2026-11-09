<?php

namespace OfflinePayments;

/**
 * Builds the 40-digit code a phone dials with no connection. Reference implementation of the format: the
 * server accepts the previous, current and next 5-minute window, so the clock that calls this must be within
 * about 5 minutes of real time.
 */
final class DeviceCode
{
    /**
     * @param string $deviceKey      the device_key from enrolDevice(): base64, or the 32 raw bytes
     * @param array{accountNumber:string, bankCode:string} $source       the subscriber's own account
     * @param array{accountNumber:string, bankCode:string} $destination  the merchant's account, as shown on its QR
     */
    public static function build(
        string $deviceKey, string $deviceShortId, int $counter, int $amountMinor, string $merchantShortId,
        array $source, array $destination, string $currency = 'NGN', ?int $now = null,
    ): string {
        if (! preg_match('/^\d{8}$/', $deviceShortId) || ! preg_match('/^\d{8}$/', $merchantShortId)) {
            throw new \InvalidArgumentException('short ids must be 8 digits');
        }
        if ($counter < 1 || $counter > 99999) {
            throw new \InvalidArgumentException('counter must be 1..99999');
        }
        if ($amountMinor < 1 || $amountMinor > 99999999) {
            throw new \InvalidArgumentException('amountMinor must be 1..99999999');
        }

        $raw = strlen($deviceKey) === 32 ? $deviceKey : base64_decode($deviceKey, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \InvalidArgumentException('deviceKey must be base64 of 32 bytes');
        }

        $window = intdiv($now ?? time(), 300);
        $canonical = implode('|', [
            'nunu-d1', $deviceShortId, $counter, $amountMinor, $merchantShortId, $window, strtoupper($currency),
            $source['accountNumber'], $source['bankCode'], $destination['accountNumber'], $destination['bankCode'],
        ]);

        // first 5 bytes of HMAC-SHA256, big-endian, mod 10^10, zero-padded to 10 digits
        $value = 0;
        foreach (str_split(substr(hash_hmac('sha256', $canonical, $raw, true), 0, 5)) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return '1' . $deviceShortId . str_pad((string) $counter, 5, '0', STR_PAD_LEFT) . str_pad((string) $amountMinor, 8, '0', STR_PAD_LEFT)
            . $merchantShortId . str_pad((string) ($value % 10_000_000_000), 10, '0', STR_PAD_LEFT);
    }

    /** Number, pauses, code and "#". On a shared voice number pass the tenant's 4-digit short code as $prefix. */
    public static function dialString(string $voiceNumber, string $code, string $prefix = ''): string
    {
        return $voiceNumber . ',,' . $prefix . $code . '#';
    }
}
