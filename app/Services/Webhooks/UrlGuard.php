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
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Webhook URLs must point to a public address.');
            }
        }

        return array_values($ips);
    }
}
