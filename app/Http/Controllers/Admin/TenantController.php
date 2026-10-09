<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentCode;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\VoiceNumber;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditLogService;
use App\Services\Codes\CodeState;
use App\Services\Settlement\SettlementManager;
use App\Services\Tenants\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function index(): View
    {
        $tenants = Tenant::query()
            ->withCount([
                'apiKeys as active_keys_count' => fn ($q) => $q->whereNull('revoked_at'),
            ])
            ->orderBy('name')
            ->get();

        $pending = Transaction::withoutGlobalScopes()->where('status', 'pending')
            ->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        return view('admin.tenants.index', ['tenants' => $tenants, 'pending' => $pending]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'voice_mode' => ['required', Rule::in(['own', 'shared'])],
        ]);

        [$tenant, $key] = $provisioner->create($data['name'], $data['voice_mode']);

        $this->audit->record($tenant->id, 'admin.tenant_created', 'admin', $request->user('admin')->id, Tenant::class, $tenant->id, 'Tenant created');

        return redirect()->route('admin.tenants.show', $tenant)->with('secret', ['label' => 'API key', 'value' => $key]);
    }

    public function show(Tenant $tenant): View
    {
        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'keys' => $tenant->apiKeys()->orderByDesc('id')->get(),
            'numbers' => VoiceNumber::where('tenant_id', $tenant->id)->orderBy('number')->get(),
            'deliveries' => WebhookDelivery::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->limit(15)->get(),
            'audit' => AuditLog::where('tenant_id', $tenant->id)->latest('id')->limit(15)->get(),
            'pendingTransactions' => Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
            'adapters' => SettlementManager::ADAPTERS,
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'environment' => ['required', Rule::in(['sandbox', 'live'])],
            'settlement_adapter' => ['required', Rule::in(SettlementManager::ADAPTERS)],
            'voice_mode' => ['required', Rule::in(['own', 'shared'])],
            'code_ttl_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'bind_caller' => ['sometimes', 'boolean'],
            'max_amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);

        // The simulator must never settle a live tenant's money.
        if ($data['environment'] === 'live' && $data['settlement_adapter'] === 'sandbox') {
            throw ValidationException::withMessages(['environment' => 'A live tenant needs a real settlement adapter, not the sandbox.']);
        }

        // Codes already out were issued with the old prefix; switching would strand them.
        if ($data['voice_mode'] !== $tenant->voice_mode
            && PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->whereIn('state', [CodeState::Issued->value, CodeState::Redeemed->value])->exists()) {
            throw ValidationException::withMessages(['voice_mode' => 'Wait until the tenant has no open codes before changing the voice mode.']);
        }

        $settings = $tenant->settings ?? [];
        $max = $data['max_amount_minor'] ?? null;

        if ($max !== null) {
            $settings['max_amount_minor'] = (int) $max;
        } else {
            unset($settings['max_amount_minor']);
        }

        $before = $tenant->only(['status', 'environment', 'settlement_adapter', 'voice_mode', 'code_ttl_minutes', 'bind_caller']);

        $tenant->update([
            'status' => $data['status'],
            'environment' => $data['environment'],
            'settlement_adapter' => $data['settlement_adapter'],
            'voice_mode' => $data['voice_mode'],
            'code_ttl_minutes' => $data['code_ttl_minutes'],
            'bind_caller' => $request->boolean('bind_caller'),
            'settings' => $settings,
        ]);

        $this->audit->record($tenant->id, 'admin.tenant_updated', 'admin', $request->user('admin')->id, Tenant::class, $tenant->id,
            'Tenant settings changed', ['before' => $before, 'after' => $tenant->only(array_keys($before))]);

        return back()->with('status', 'Saved.');
    }

    public function verifyAudit(Tenant $tenant): RedirectResponse
    {
        $broken = $this->audit->verifyChain($tenant->id);

        return back()->with('status', $broken === []
            ? 'Audit chain is intact.'
            : 'Audit chain is BROKEN at entries: ' . implode(', ', $broken) . '.');
    }
}
