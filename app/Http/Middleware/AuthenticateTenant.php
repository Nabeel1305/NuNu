<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenant
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $badKeyLimit = 'api:bad-key:' . $request->ip();

        if (RateLimiter::tooManyAttempts($badKeyLimit, (int) config('platform.api.bad_key_per_ip_per_minute'))) {
            return $this->error('too_many_attempts', 'Too many requests with an invalid key. Try again shortly.', 429);
        }

        $plain = (string) $request->bearerToken();
        $prefix = ApiKey::prefixOf($plain);

        $key = $prefix ? ApiKey::with('tenant')->where('prefix', $prefix)->whereNull('revoked_at')->first() : null;

        if (! $key || ! $key->matches($plain)) {
            RateLimiter::hit($badKeyLimit, 60);

            return $this->error('unauthenticated', 'Missing or invalid API key.', 401);
        }

        if (! $key->tenant->isActive()) {
            return $this->error('tenant_suspended', 'This account is suspended.', 403);
        }

        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) > 60) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        $this->context->set($key->tenant);
        $request->attributes->set('tenant', $key->tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
