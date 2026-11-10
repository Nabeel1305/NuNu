<?php

namespace App\Services\Voice;

use App\Models\Subscriber;
use App\Models\Tenant;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * When a call reaches the platform but its code is refused, the payer hears the same "thank you" as always and nothing
 * happens. If the caller's phone is a payer the tenant registered, tell the tenant (a `code.rejected` event with the
 * reason) so it can text them. Never says more than the reason; never carries the digits.
 */
class CallProblemNotifier
{
    public function __construct(private readonly TenantContext $context, private readonly WebhookDispatcher $webhooks)
    {
    }

    public function notify(Tenant $tenant, VoiceCall $call, ?string $reason): void
    {
        if ($reason === null || $reason === 'rate_limited' || $call->digits === null) {
            return;
        }

        $this->context->run($tenant, function () use ($tenant, $call, $reason) {
            $key = PhoneNumber::normalize($call->caller);
            $subscriber = $key === '' ? null : Subscriber::where('phone_normalized', $key)->first();
            if (! $subscriber) {
                return;     // not someone the tenant knows: nothing to tell
            }

            // A caller number can be faked, so one payer cannot be sent a stream of these.
            if (! RateLimiter::attempt("voice:problem:{$tenant->id}:{$subscriber->id}", 1, fn () => true, 120)) {
                return;
            }

            $this->webhooks->dispatch($tenant, 'code.rejected', [
                'origin' => 'voice',
                'reason' => $reason,
                'subscriber_reference' => $subscriber->reference,
                'merchant_reference' => null,
                'amount_minor' => null,
                'currency' => null,
            ]);
        });
    }
}
