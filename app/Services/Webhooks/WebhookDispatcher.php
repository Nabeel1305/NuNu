<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    /** Queue one signed delivery per endpoint that subscribes to this event. */
    public function dispatch(Tenant $tenant, string $type, array $data): void
    {
        $this->context->run($tenant, function () use ($tenant, $type, $data) {
            $eventId = (string) Str::uuid();

            $payload = [
                'id' => $eventId,
                'type' => $type,
                'created_at' => now()->toIso8601String(),
                'data' => $data,
            ];

            WebhookEndpoint::query()->get()
                ->filter(fn (WebhookEndpoint $e) => $e->wants($type))
                ->each(function (WebhookEndpoint $endpoint) use ($tenant, $eventId, $type, $payload) {
                    $delivery = WebhookDelivery::create([
                        'tenant_id' => $tenant->id,
                        'webhook_endpoint_id' => $endpoint->id,
                        'event_id' => $eventId,
                        'event_type' => $type,
                        'payload' => $payload,
                        'next_attempt_at' => now(),
                    ]);

                    try {
                        DeliverWebhook::dispatch($delivery->id, $tenant->id);
                    } catch (\Throwable $e) {
                        // The delivery row is saved; a queue or endpoint problem must
                        // never break the call that raised the event.
                        Log::error('Could not dispatch webhook', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);
                    }
                });
        });
    }
}
