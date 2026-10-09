<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogService;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** API keys, webhook endpoints and the delivery log. Secrets are shown once and never stored readable. */
class DeveloperController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    // ── API keys ──────────────────────────────────────────────────────────────

    public function keys(Request $request): View
    {
        return view('portal.developer.keys', [
            'keys' => ApiKey::where('tenant_id', $this->tenant($request)->id)->orderByDesc('id')->get(),
        ]);
    }

    public function issueKey(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $tenant = $this->tenant($request);

        [$key, $plain] = ApiKey::issue($tenant, $data['name']);
        $this->record($request, 'portal.key_issued', "API key {$key->prefix} issued", ApiKey::class, $key->id);

        return back()->with('secret', ['label' => 'API key', 'value' => $plain]);
    }

    public function revokeKey(Request $request, int $key): RedirectResponse
    {
        $apiKey = ApiKey::where('tenant_id', $this->tenant($request)->id)->findOrFail($key);
        $apiKey->update(['revoked_at' => $apiKey->revoked_at ?? now()]);
        $this->record($request, 'portal.key_revoked', "API key {$apiKey->prefix} revoked", ApiKey::class, $apiKey->id);

        return back()->with('status', 'Key revoked. Anything still using it will now be refused.');
    }

    // ── Webhook endpoints ─────────────────────────────────────────────────────

    public function webhooks(): View
    {
        $endpoints = WebhookEndpoint::orderByDesc('id')->get();
        $lastDelivery = WebhookDelivery::selectRaw('webhook_endpoint_id, max(id) as last_id, sum(case when status = ? then 1 else 0 end) as failed', ['failed'])
            ->groupBy('webhook_endpoint_id')->get()->keyBy('webhook_endpoint_id');

        return view('portal.developer.webhooks', ['endpoints' => $endpoints, 'events' => WebhookEndpoint::EVENTS, 'stats' => $lastDelivery]);
    }

    public function storeWebhook(Request $request, UrlGuard $guard): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(WebhookEndpoint::EVENTS)],
        ]);

        $this->assertPublic($guard, $data['url']);

        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $endpoint = WebhookEndpoint::create([
            'url' => $data['url'],
            'events' => empty($data['events']) ? null : array_values($data['events']),
            'secret' => $secret,
        ]);
        $this->record($request, 'portal.webhook_created', "Webhook endpoint {$endpoint->url} added", WebhookEndpoint::class, $endpoint->id);

        return back()->with('secret', ['label' => 'Signing secret for ' . $endpoint->url, 'value' => $secret]);
    }

    public function updateWebhook(Request $request, int $endpoint, UrlGuard $guard): RedirectResponse
    {
        $model = WebhookEndpoint::findOrFail($endpoint);
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(WebhookEndpoint::EVENTS)],
        ]);
        $this->assertPublic($guard, $data['url']);

        $model->update(['url' => $data['url'], 'events' => empty($data['events']) ? null : array_values($data['events'])]);
        $this->record($request, 'portal.webhook_updated', "Webhook endpoint {$model->url} updated", WebhookEndpoint::class, $model->id);

        return back()->with('status', 'Endpoint updated.');
    }

    public function toggleWebhook(Request $request, int $endpoint): RedirectResponse
    {
        $model = WebhookEndpoint::findOrFail($endpoint);
        $model->update(['active' => ! $model->active]);
        $this->record($request, 'portal.webhook_toggled', "Webhook endpoint {$model->url} " . ($model->active ? 'enabled' : 'paused'), WebhookEndpoint::class, $model->id);

        return back()->with('status', $model->active ? 'Endpoint enabled.' : 'Endpoint paused. New events will not be sent to it.');
    }

    public function rotateWebhookSecret(Request $request, int $endpoint): RedirectResponse
    {
        $model = WebhookEndpoint::findOrFail($endpoint);
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $model->update(['secret' => $secret]);
        $this->record($request, 'portal.webhook_secret_rotated', "Signing secret for {$model->url} rotated", WebhookEndpoint::class, $model->id);

        return back()->with('secret', ['label' => 'New signing secret for ' . $model->url, 'value' => $secret]);
    }

    public function destroyWebhook(Request $request, int $endpoint): RedirectResponse
    {
        $model = WebhookEndpoint::findOrFail($endpoint);
        $this->record($request, 'portal.webhook_deleted', "Webhook endpoint {$model->url} deleted", WebhookEndpoint::class, $model->id);
        $model->delete();

        return back()->with('status', 'Endpoint deleted.');
    }

    public function testWebhook(Request $request, int $endpoint): RedirectResponse
    {
        $model = WebhookEndpoint::findOrFail($endpoint);
        $tenant = $this->tenant($request);
        $eventId = (string) Str::uuid();

        $delivery = WebhookDelivery::create([
            'tenant_id' => $tenant->id,
            'webhook_endpoint_id' => $model->id,
            'event_id' => $eventId,
            'event_type' => 'webhook.test',
            'payload' => ['id' => $eventId, 'type' => 'webhook.test', 'created_at' => now()->toIso8601String(), 'data' => ['message' => 'Test event from the portal. No payment is involved.']],
            'next_attempt_at' => now(),
        ]);
        $this->queue($delivery, $tenant);
        $this->record($request, 'portal.webhook_tested', "Test event sent to {$model->url}", WebhookEndpoint::class, $model->id);

        return redirect()->route('portal.deliveries.show', $delivery->id)->with('status', 'Test event queued.');
    }

    // ── Deliveries ────────────────────────────────────────────────────────────

    public function deliveries(Request $request): View
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:pending,delivered,failed'],
            'endpoint' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:60'],
        ]) + ['status' => null, 'endpoint' => null, 'event' => null];

        $rows = WebhookDelivery::with('endpoint')
            ->when($f['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($f['endpoint'], fn ($q, $v) => $q->where('webhook_endpoint_id', $v))
            ->when($f['event'], fn ($q, $v) => $q->where('event_type', $v))
            ->latest('id')->paginate(25)->withQueryString();

        return view('portal.developer.deliveries', ['rows' => $rows, 'filters' => $f, 'endpoints' => WebhookEndpoint::orderBy('url')->get(), 'events' => array_merge(WebhookEndpoint::EVENTS, ['webhook.test'])]);
    }

    public function delivery(int $delivery): View
    {
        return view('portal.developer.delivery', ['delivery' => WebhookDelivery::with('endpoint')->findOrFail($delivery)]);
    }

    public function retry(Request $request, int $delivery): RedirectResponse
    {
        $model = WebhookDelivery::findOrFail($delivery);
        abort_if($model->status === 'delivered', 409, 'Already delivered.');

        $model->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null, 'last_status_code' => null]);
        $this->queue($model, $this->tenant($request));
        $this->record($request, 'portal.delivery_retried', "Delivery {$model->event_id} queued again", WebhookDelivery::class, $model->id);

        return back()->with('status', 'Delivery queued again.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    private function assertPublic(UrlGuard $guard, string $url): void
    {
        try {
            $guard->assertPublic($url);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }

    /** A queue problem must never lose the delivery row; it stays pending and can be retried. */
    private function queue(WebhookDelivery $delivery, Tenant $tenant): void
    {
        try {
            DeliverWebhook::dispatch($delivery->id, $tenant->id);
        } catch (\Throwable $e) {
            Log::error('Could not dispatch webhook', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);
        }
    }

    private function record(Request $request, string $event, string $description, string $subjectType, int $subjectId): void
    {
        $user = $request->user('tenant');
        $this->audit->record($this->tenant($request)->id, $event, 'tenant_user', $user->id, $subjectType, $subjectId, "{$user->email}: {$description}");
    }
}
