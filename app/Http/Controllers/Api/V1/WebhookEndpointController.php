<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebhookEndpointController extends Controller
{
    public function store(Request $request, UrlGuard $guard): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['nullable', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEndpoint::EVENTS)],
        ]);

        try {
            $guard->assertPublic($data['url']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $secret = 'whsec_' . bin2hex(random_bytes(24));

        $endpoint = WebhookEndpoint::create([
            'url' => $data['url'],
            'events' => $data['events'] ?? null,
            'secret' => $secret,
        ]);

        // The signing secret is shown once, here, and never again.
        return response()->json([
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'secret' => $secret,
        ], 201);
    }
}
