<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Every tenant's webhook endpoints and deliveries. Retry, test, pause and rotate secrets on a tenant's behalf. */
class WebhooksController extends Controller
{
    public function endpoints(Request $request): View
    {
        $rows = DB::table('webhook_endpoints as e')->join('tenants as n', 'n.id', '=', 'e.tenant_id')
            ->selectRaw('e.id, e.url, e.active, e.events, n.id as tenant_id, n.name as tenant, '
                . "(select count(*) from webhook_deliveries d where d.webhook_endpoint_id = e.id and d.status = 'failed' and d.created_at >= ?) as failed_24h, "
                . "(select count(*) from webhook_deliveries d where d.webhook_endpoint_id = e.id and d.status = 'delivered' and d.created_at >= ?) as ok_24h", [now()->subDay(), now()->subDay()])
            ->when($request->filled('tenant'), fn ($b) => $b->where('e.tenant_id', (int) $request->query('tenant')))
            ->orderBy('n.name')->orderBy('e.id')->get();

        return view('admin.webhooks.endpoints', ['rows' => $rows, 'tenants' => Tenant::orderBy('name')->pluck('name', 'id')]);
    }

    public function deliveries(Request $request): View
    {
        $f = $request->validate(['status' => ['nullable', 'in:pending,delivered,failed'], 'tenant' => ['nullable', 'integer'], 'event' => ['nullable', 'string', 'max:60']]);

        $rows = WebhookDelivery::withoutGlobalScopes()->join('tenants as n', 'n.id', '=', 'webhook_deliveries.tenant_id')->join('webhook_endpoints as e', 'e.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->select('webhook_deliveries.*', 'n.name as tenant', 'e.url')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('webhook_deliveries.status', $v))
            ->when($f['tenant'] ?? null, fn ($q, $v) => $q->where('webhook_deliveries.tenant_id', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('webhook_deliveries.event_type', $v))
            ->orderByDesc('webhook_deliveries.id')->paginate(40)->withQueryString();

        return view('admin.webhooks.deliveries', ['rows' => $rows, 'tenants' => Tenant::orderBy('name')->pluck('name', 'id'), 'events' => array_merge(WebhookEndpoint::EVENTS, ['webhook.test'])]);
    }

    public function delivery(int $delivery): View
    {
        $d = WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery);

        return view('admin.webhooks.delivery', ['d' => $d, 'tenant' => Tenant::find($d->tenant_id), 'endpoint' => WebhookEndpoint::withoutGlobalScopes()->find($d->webhook_endpoint_id)]);
    }

    public function retry(Request $request, int $delivery): RedirectResponse
    {
        $d = WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery);
        $request->attributes->set('audit_tenant_id', $d->tenant_id);
        abort_if($d->status === 'delivered', 409, 'Already delivered.');

        $d->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null, 'last_status_code' => null]);
        $this->queue($d);

        return back()->with('status', 'Delivery queued again.');
    }

    public function test(Request $request, int $endpoint): RedirectResponse
    {
        $e = WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint);
        $request->attributes->set('audit_tenant_id', $e->tenant_id);
        $id = (string) Str::uuid();
        $d = WebhookDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $e->tenant_id, 'webhook_endpoint_id' => $e->id, 'event_id' => $id, 'event_type' => 'webhook.test',
            'payload' => ['id' => $id, 'type' => 'webhook.test', 'created_at' => now()->toIso8601String(), 'data' => ['message' => 'Test event from the platform operator. No payment is involved.']],
            'next_attempt_at' => now(),
        ]);
        $this->queue($d);

        return redirect()->route('admin.deliveries.show', $d->id)->with('status', 'Test event queued.');
    }

    public function toggle(Request $request, int $endpoint): RedirectResponse
    {
        $e = WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint);
        $request->attributes->set('audit_tenant_id', $e->tenant_id);
        $e->update(['active' => ! $e->active]);

        return back()->with('status', $e->active ? 'Endpoint resumed.' : 'Endpoint paused.');
    }

    public function rotate(Request $request, int $endpoint): RedirectResponse
    {
        $e = WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint);
        $request->attributes->set('audit_tenant_id', $e->tenant_id);
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $e->update(['secret' => $secret]);

        return back()->with('secret', ['label' => "New signing secret for {$e->url} (give it to the tenant; the old one stops working)", 'value' => $secret]);
    }

    private function queue(WebhookDelivery $d): void
    {
        try {
            DeliverWebhook::dispatch($d->id, $d->tenant_id);
        } catch (\Throwable $e) {
            Log::error('Could not dispatch webhook', ['delivery' => $d->id, 'error' => $e->getMessage()]);
        }
    }
}
