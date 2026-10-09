<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\UrlGuard;
use App\Services\Webhooks\WebhookSigner;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $deliveryId, public readonly int $tenantId)
    {
        // The first attempt plus one per backoff step.
        $this->tries = count(config('platform.webhooks.backoff', [])) + 1;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('platform.webhooks.backoff', []);
    }

    public function handle(WebhookSigner $signer, UrlGuard $guard, TenantContext $context): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        $context->run($tenant, function () use ($signer, $guard) {
            $delivery = WebhookDelivery::with('endpoint')->findOrFail($this->deliveryId);

            if ($delivery->status === 'delivered') {
                return;
            }

            $delivery->increment('attempts');

            $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);

            try {
                $url = $delivery->endpoint->url;
                $ips = $guard->assertPublic($url);

                $request = Http::timeout((int) config('platform.webhooks.timeout', 10))
                    // A redirect could send us to an internal address the check never saw.
                    ->withoutRedirecting();

                if ($ips !== []) {
                    // Connect to the address we just checked, not whatever DNS says now.
                    $host = parse_url($url, PHP_URL_HOST);
                    $port = parse_url($url, PHP_URL_PORT) ?: 443;
                    $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]]]);
                }

                $response = $request
                    ->withHeaders([
                        'Offline-Signature' => $signer->header($delivery->endpoint->secret, $body),
                        'Offline-Event-Id' => $delivery->event_id,
                    ])
                    ->withBody($body, 'application/json')
                    ->post($delivery->endpoint->url);
            } catch (\Throwable $e) {
                $delivery->update([
                    'last_error' => mb_substr($e->getMessage(), 0, 250),
                    'last_status_code' => null,
                    'next_attempt_at' => $this->nextAttemptAt($delivery->attempts),
                ]);

                throw $e;
            }

            if ($response->successful()) {
                $delivery->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'last_status_code' => $response->status(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                ]);

                return;
            }

            $delivery->update([
                'last_status_code' => $response->status(),
                'last_error' => 'Endpoint answered ' . $response->status(),
                'next_attempt_at' => $this->nextAttemptAt($delivery->attempts),
            ]);

            throw new \RuntimeException('Webhook endpoint answered ' . $response->status());
        });
    }

    private function nextAttemptAt(int $attempts): ?\Illuminate\Support\Carbon
    {
        $delay = config('platform.webhooks.backoff', [])[$attempts - 1] ?? null;

        return $delay === null ? null : now()->addSeconds($delay);
    }

    public function failed(\Throwable $e): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant) {
            app(TenantContext::class)->run($tenant, function () {
                WebhookDelivery::whereKey($this->deliveryId)->update(['status' => 'failed', 'next_attempt_at' => null]);
            });
        }
    }
}
