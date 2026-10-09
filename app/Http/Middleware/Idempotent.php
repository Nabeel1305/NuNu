<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a POST safe to retry. The same Idempotency-Key with the same body
 * replays the first response; the same key with a different body is refused.
 * Must run after AuthenticateTenant, because keys are scoped per tenant.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key');

        if ($key === '' || strlen($key) > 100) {
            return $this->error('idempotency_key_required', 'Send an Idempotency-Key header of up to 100 characters.', 422);
        }

        $hash = hash('sha256', $request->method() . '|' . $request->path() . '|' . $request->getContent());

        try {
            $record = IdempotencyKey::create(['key' => $key, 'request_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            $existing = IdempotencyKey::where('key', $key)->firstOrFail();

            if (! hash_equals($existing->request_hash, $hash)) {
                return $this->error('idempotency_key_reused', 'This key was already used with a different request.', 422);
            }

            if ($existing->response_status === null) {
                return $this->error('request_in_progress', 'The original request is still being processed.', 409);
            }

            return response()->json($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();

            throw $e;
        }

        // Server errors are not remembered, so the caller can retry.
        if ($response->getStatusCode() >= 500) {
            $record->delete();
        } else {
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => json_decode($response->getContent(), true),
            ]);
        }

        return $response;
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
