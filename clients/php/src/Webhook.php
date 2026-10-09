<?php

namespace OfflinePayments;

class Webhook
{
    /**
     * Check a delivery's Offline-Signature header against the raw body.
     * Pass the body exactly as received; re-encoding parsed JSON changes the bytes.
     */
    public static function verify(string $secret, string $header, string $rawBody, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $m[1]) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $rawBody, $secret), $m[2]);
    }
}
