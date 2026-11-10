<?php

namespace App\Http\Controllers;

use App\Models\VoiceCallLog;
use App\Models\VoiceNumber;
use App\Services\Codes\CodeService;
use App\Services\Voice\AfricasTalkingAdapter;
use App\Services\Voice\VoiceRouter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class VoiceController extends Controller
{
    public function africastalking(
        Request $request,
        AfricasTalkingAdapter $at,
        VoiceRouter $router,
        CodeService $codes,
        \App\Services\Voice\CallProblemNotifier $problems,
    ): Response {
        $call = $at->parse($request);
        $token = (string) $request->query('token', '');

        // Only requests with a bad token count against the source address: real
        // callbacks all come from the provider, so a blanket per-IP limit would
        // throttle every caller at once.
        $badTokenKey = 'voice:bad-token:' . $request->ip();

        if (RateLimiter::tooManyAttempts($badTokenKey, config('platform.voice.bad_token_per_ip_per_minute'))) {
            $this->record($call, null, 'throttled', 'rate_limited');

            return response('', 429);
        }

        // Authenticate before looking at anything else: the number must exist,
        // be active, belong to this provider, and the URL token must match.
        $number = VoiceNumber::with('tenant')
            ->where('number', $call->destination)
            ->where('provider', AfricasTalkingAdapter::PROVIDER)
            ->where('active', true)
            ->first();

        if (! $number || ! $number->acceptsToken($token)) {
            RateLimiter::hit($badTokenKey, 60);

            // Say why, to whoever reads the call log (the caller only ever gets a 403).
            $any = VoiceNumber::where('number', $call->destination)->first();
            $this->record($call, $any?->tenant_id, 'denied', ! $any ? 'number_not_registered' : (! $any->active ? 'number_inactive' : 'wrong_token'));

            return response('', 403);
        }

        // Per number, and only after authentication, so a stranger cannot use up
        // another number's budget.
        $numberKey = 'voice:number:' . $number->id;

        if (RateLimiter::tooManyAttempts($numberKey, config('platform.voice.per_number_per_minute'))) {
            $this->record($call, $number->tenant_id, 'throttled', 'rate_limited');

            return response('', 429);
        }

        RateLimiter::hit($numberKey, 60);

        if ($call->digits === null) {
            $callback = $request->url() . '?token=' . rawurlencode($token);
            $this->record($call, $number->tenant_id, 'prompt', null);

            return $this->xml($at->promptForDigits($callback));
        }

        $tenant = $router->tenantFor($number, $call->digits);

        if ($tenant) {
            $outcome = $codes->redeem($tenant, $call->digits, $call->caller);

            if (! $outcome->accepted) {
                Log::info('Voice redemption rejected', ['tenant' => $tenant->id, 'reason' => $outcome->reason, 'session' => $call->sessionId]);
            }
            $this->record($call, $tenant->id, $outcome->accepted ? 'accepted' : 'rejected', $outcome->reason);

            // Device-signed codes already report a refusal of a genuine code; this covers everything else, so the payer is told.
            if (! $outcome->accepted && ! $outcome->reported) {
                $problems->notify($tenant, $call, $outcome->reason);
            }
        } else {
            $this->record($call, $number->tenant_id, 'no_tenant', 'no_tenant');
        }

        return $this->xml($at->acknowledge());
    }

    /** A row in the call log. It must never get in the way of the call, so any failure here is swallowed. */
    private function record(\App\Services\Voice\VoiceCall $call, ?int $tenantId, string $outcome, ?string $reason): void
    {
        try {
            $digits = preg_replace('/\D/', '', (string) $call->digits);
            VoiceCallLog::create([
                'tenant_id' => $tenantId,
                'destination' => mb_substr($call->destination, 0, 32),
                'caller_tail' => substr(preg_replace('/\D/', '', $call->caller), -4) ?: null,
                'session_id' => mb_substr($call->sessionId, 0, 100),
                'step' => $call->digits === null ? 'prompt' : 'digits',
                'digits_length' => $call->digits === null ? null : strlen($digits),
                'outcome' => $outcome,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write the voice call log', ['error' => $e->getMessage()]);
        }
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml']);
    }
}
