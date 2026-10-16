<?php

namespace App\Services\Offline;

/**
 * The device-signed offline code: what a phone dials with no data connection.
 *
 *   V D…D C…C A…A M…M S…S      40 digits, fixed width, nothing separated
 *   1  8   5   8   8   10
 *   │  │   │   │   │   └ MAC: truncated HMAC over the full details below
 *   │  │   │   │   └ merchant short id
 *   │  │   │   └ amount in minor units (kobo)
 *   │  │   └ counter: strictly increasing per device, so a code works once
 *   │  └ device short id (assigned at enrolment)
 *   └ version (1)
 *
 * Only identifiers travel in the clear. The MAC covers the identifiers AND the account details they stand
 * for (subscriber's and merchant's account number and bank code), which the server rebuilds from its own
 * records, so a code can't be re-pointed at another account and no account number is ever dialled.
 *
 * Reference implementations live in clients/js and clients/php; docs/ and the test vectors in
 * tests/Unit/DeviceCodeFormatTest.php pin the exact bytes.
 */
final class DeviceCodeFormat
{
    public const VERSION = '1';
    public const LENGTH = 40;
    public const WINDOW_SECONDS = 300;

    private const CANONICAL_PREFIX = 'nunu-d1';

    /**
     * @return array{version:string, device:string, counter:int, amount:int, merchant:string, mac:string}|null
     *         null when the digits are not a well-formed code of this version
     */
    public static function parse(string $digits): ?array
    {
        if (strlen($digits) !== self::LENGTH || ! ctype_digit($digits) || $digits[0] !== self::VERSION) {
            return null;
        }

        return [
            'version' => substr($digits, 0, 1),
            'device' => substr($digits, 1, 8),
            'counter' => (int) substr($digits, 9, 5),
            'amount' => (int) substr($digits, 14, 8),
            'merchant' => substr($digits, 22, 8),
            'mac' => substr($digits, 30, 10),
        ];
    }

    /** Time bucket, not transmitted: the server accepts the previous, current and next one. */
    public static function window(int $timestamp): int
    {
        return intdiv($timestamp, self::WINDOW_SECONDS);
    }

    /**
     * The exact text that is signed. Integers are plain decimal (no padding); the two short ids keep
     * their leading zeros.
     */
    public static function canonical(
        string $device, int $counter, int $amount, string $merchant, int $window, string $currency,
        string $sourceAccount, string $sourceBank, string $destinationAccount, string $destinationBank,
    ): string {
        return implode('|', [
            self::CANONICAL_PREFIX, $device, $counter, $amount, $merchant, $window, strtoupper($currency),
            $sourceAccount, $sourceBank, $destinationAccount, $destinationBank,
        ]);
    }

    /** First 5 bytes of HMAC-SHA256 as a big-endian integer, mod 10^10, zero-padded to 10 digits. */
    public static function mac(string $rawKey, string $canonical): string
    {
        $raw = hash_hmac('sha256', $canonical, $rawKey, true);

        $value = 0;
        foreach (str_split(substr($raw, 0, 5)) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return str_pad((string) ($value % 10_000_000_000), 10, '0', STR_PAD_LEFT);
    }

    /** Build a code. The server never does this; it exists for tests and as the reference for client libraries. */
    public static function build(
        string $rawKey, string $device, int $counter, int $amount, string $merchant, int $timestamp, string $currency,
        string $sourceAccount, string $sourceBank, string $destinationAccount, string $destinationBank,
    ): string {
        $body = self::VERSION
            . str_pad($device, 8, '0', STR_PAD_LEFT)
            . str_pad((string) $counter, 5, '0', STR_PAD_LEFT)
            . str_pad((string) $amount, 8, '0', STR_PAD_LEFT)
            . str_pad($merchant, 8, '0', STR_PAD_LEFT);

        $canonical = self::canonical($device, $counter, $amount, $merchant, self::window($timestamp), $currency, $sourceAccount, $sourceBank, $destinationAccount, $destinationBank);

        return $body . self::mac($rawKey, $canonical);
    }
}
