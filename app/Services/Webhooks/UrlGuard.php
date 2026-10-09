<?php

namespace App\Services\Webhooks;

/**
 * Stops a tenant from pointing a webhook at our own network (SSRF). Checked
 * when an endpoint is registered and again before every delivery, because DNS
 * can change between the two.
 */
class UrlGuard
{
    /**
     * Throws unless the URL is https and every address it resolves to is public.
     *
     * @return list<string> the addresses checked. Callers connect to exactly
     *         these (see DeliverWebhook), so DNS cannot change between the check
     *         and the request. Empty when the check is switched off for tests.
     */
    public function assertPublic(string $url): array
    {
        if (config('platform.webhooks.allow_private_urls')) {
            return [];
        }

        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new \InvalidArgumentException('Webhook URLs must use https.');
        }

        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new \InvalidArgumentException('Webhook host does not resolve.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || $this->isSpecialUse($ip)) {
                throw new \InvalidArgumentException('Webhook URLs must point to a public address.');
            }
        }

        return array_values($ips);
    }

    /**
     * Ranges PHP's private/reserved filters do not cover but that are still not the public internet:
     * carrier-grade NAT, IETF protocol assignments, benchmarking, documentation and multicast.
     */
    private function isSpecialUse(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;   // not a plain IPv4 address: refuse rather than guess
        }

        foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4'] as $cidr) {
            [$base, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
