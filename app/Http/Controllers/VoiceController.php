<?php

namespace App\Http\Controllers;

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
    ): Response {
        $call = $at->parse($request);
        $token = (string) $request->query('token', '');

        // Only requests with a bad token count against the source address: real
        // callbacks all come from the provider, so a blanket per-IP limit would
        // throttle every caller at once.
        $badTokenKey = 'voice:bad-token:' . $request->ip();

        if (RateLimiter::tooManyAttempts($badTokenKey, config('platform.voice.bad_token_per_ip_per_minute'))) {
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

            return response('', 403);
        }

        // Per number, and only after authentication, so a stranger cannot use up
        // another number's budget.
        $numberKey = 'voice:number:' . $number->id;

        if (RateLimiter::tooManyAttempts($numberKey, config('platform.voice.per_number_per_minute'))) {
            return response('', 429);
        }

        RateLimiter::hit($numberKey, 60);

        if ($call->digits === null) {
            $callback = $request->url() . '?token=' . rawurlencode($token);

            return $this->xml($at->promptForDigits($callback));
        }

        $tenant = $router->tenantFor($number, $call->digits);

        if ($tenant) {
            $outcome = $codes->redeem($tenant, $call->digits, $call->caller);

            if (! $outcome->accepted) {
                Log::info('Voice redemption rejected', ['tenant' => $tenant->id, 'reason' => $outcome->reason, 'session' => $call->sessionId]);
            }
        }

        return $this->xml($at->acknowledge());
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml']);
    }
}
