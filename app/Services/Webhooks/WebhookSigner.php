<?php

namespace App\Services\Webhooks;

/**
 * Header format: `t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`.
 * The timestamp is part of the signed text, so a captured delivery cannot be
 * replayed after the tolerance window.
 */
class WebhookSigner
{
    public function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function header(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . $this->sign($secret, $timestamp, $body);
    }

    public function verify(string $secret, string $header, string $body, ?int $tolerance = null, ?int $now = null): bool
    {
        $tolerance ??= (int) config('platform.webhooks.signature_tolerance', 300);
        $now ??= time();

        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
            return false;
        }

        if (abs($now - (int) $m[1]) > $tolerance) {
            return false;
        }

        return hash_equals($this->sign($secret, (int) $m[1], $body), $m[2]);
    }
}
