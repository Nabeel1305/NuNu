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

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $status = $request->query('status');

        $tenants = Tenant::query()
            ->withCount(['apiKeys as active_keys_count' => fn ($q) => $q->whereNull('revoked_at')])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like)))
            ->when(in_array($status, ['active', 'suspended', 'archived'], true), fn ($b) => $b->where('status', $status), fn ($b) => $b->where('status', '!=', 'archived'))
            ->when(in_array($request->query('environment'), ['sandbox', 'live'], true), fn ($b) => $b->where('environment', $request->query('environment')))
            ->orderBy('name')->paginate(25)->withQueryString();

        $pending = Transaction::withoutGlobalScopes()->where('status', 'pending')
            ->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        return view('admin.tenants.index', ['tenants' => $tenants, 'pending' => $pending, 'archivedCount' => Tenant::where('status', 'archived')->count()]);
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
            'portalUsers' => \App\Models\TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('role')->orderBy('name')->get(),
            'deliveries' => WebhookDelivery::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->limit(15)->get(),
            'audit' => AuditLog::where('tenant_id', $tenant->id)->latest('id')->limit(15)->get(),
            'pendingTransactions' => Transaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
            'adapters' => SettlementManager::ADAPTERS,
            'checklist' => $this->checklist($tenant),
            'otherTenants' => Tenant::where('id', '!=', $tenant->id)->where('status', '!=', 'archived')->orderBy('name')->pluck('name', 'id'),
            'hasPayments' => PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists(),
        ]);
    }

    /** What must be true before a tenant handles real money. @return list<array{label:string,ok:bool,hint:string,required:bool}> */
    private function checklist(Tenant $tenant): array
    {
        $owners = \App\Models\TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->where('is_active', true)->whereNotNull('password')->get();
        $numbers = VoiceNumber::where('tenant_id', $tenant->id)->where('active', true)->count();
        $pool = VoiceNumber::whereNull('tenant_id')->where('active', true)->count();
        $row = fn (string $label, bool $ok, string $hint, bool $required = true) => compact('label', 'ok', 'hint', 'required');

        return [
            $row('An owner has signed in and set a password', $owners->isNotEmpty(), 'Invite an owner under Portal users.'),
            $row('An owner has two-factor on', $owners->contains(fn ($u) => $u->hasTwoFactor()), 'Ask them to set up their authenticator.'),
            $row('An active API key', $tenant->apiKeys()->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists(), 'Issue a key below.'),
            $row('A voice number to ring', $tenant->usesSharedNumber() ? $pool > 0 : $numbers > 0, $tenant->usesSharedNumber() ? 'The shared pool has no active number.' : 'Add a number below.'),
            $row('A webhook endpoint for payment events', \App\Models\WebhookEndpoint::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->exists(), 'The tenant adds one in its portal (or an operator can see them under Webhooks).'),
            $row('A real settlement adapter', $tenant->settlement_adapter !== 'sandbox', 'The sandbox adapter only simulates settlement. A live tenant cannot use it.'),
            $row('Offline phone codes ready', ! $tenant->setting('device_codes.enabled', false) || filled(config('platform.device_key_secret')), 'PLATFORM_DEVICE_KEY_SECRET is not set on the server.', false),
        ];
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
            'device_codes_enabled' => ['sometimes', 'boolean'],
            'device_max_amount_minor' => ['nullable', 'integer', 'min:1'],
            'device_daily_limit_minor' => ['nullable', 'integer', 'min:1'],
            'direct_fields' => ['sometimes', 'boolean'],
            'direct_enabled' => ['sometimes', 'boolean'],
            'direct_max_amount_minor' => ['nullable', 'integer', 'min:1'],
            'short_code' => ['nullable', 'digits:4', Rule::unique('tenants', 'short_code')->ignore($tenant->id)],
            'confirm_live' => ['nullable', 'string'],
        ]);

        if ($tenant->status === 'archived') {
            throw ValidationException::withMessages(['status' => 'This tenant is archived. Restore it first.']);
        }

        // Going live is the step that makes the platform move real money: make the operator mean it.
        if ($data['environment'] === 'live' && $data['settlement_adapter'] === 'sandbox') {
            throw ValidationException::withMessages(['environment' => 'A live tenant needs a real settlement adapter, not the sandbox.']);
        }
        if ($data['environment'] === 'live' && $tenant->environment !== 'live') {
            if (($data['confirm_live'] ?? '') !== $tenant->slug) {
                throw ValidationException::withMessages(['confirm_live' => 'To go live, type the tenant\'s identifier (' . $tenant->slug . ') in the confirmation box.']);
            }
            $missing = collect($this->checklist($tenant))->where('required', true)->where('ok', false)->pluck('label');
            if ($missing->isNotEmpty() && ! $request->boolean('override_checklist')) {
                throw ValidationException::withMessages(['environment' => 'Not ready to go live: ' . $missing->implode('; ') . '. Tick "go live anyway" if you are sure.']);
            }
        }

        // The short code prefixes every code on a shared number; changing it strands codes already out.
        if (($data['short_code'] ?? null) && $data['short_code'] !== $tenant->short_code
            && PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('state', [CodeState::Issued->value, CodeState::Redeemed->value])->exists()) {
            throw ValidationException::withMessages(['short_code' => 'Wait until the tenant has no open codes before changing its short code.']);
        }

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

        // Offline codes built on a phone, with caps because nothing is held when they are built.
        $device = ['enabled' => $request->boolean('device_codes_enabled')];
        foreach (['max_amount_minor' => 'device_max_amount_minor', 'daily_limit_minor' => 'device_daily_limit_minor'] as $key => $field) {
            if (($data[$field] ?? null) !== null) {
                $device[$key] = (int) $data[$field];
            }
        }
        // The bank's own choices (set in its portal) survive the operator saving this form, unless the form carries them.
        if ($request->boolean('direct_fields')) {
            $device['direct_enabled'] = $request->boolean('direct_enabled');
            if (($data['direct_max_amount_minor'] ?? null) !== null) {
                $device['direct_max_amount_minor'] = (int) $data['direct_max_amount_minor'];
            }
        } else {
            foreach (['direct_enabled', 'direct_max_amount_minor'] as $kept) {
                if (($existing = $tenant->setting("device_codes.$kept")) !== null) {
                    $device[$kept] = $existing;
                }
            }
        }
        $settings['device_codes'] = $device;

        $before = $tenant->only(['status', 'environment', 'settlement_adapter', 'voice_mode', 'code_ttl_minutes', 'bind_caller']) + ['device_codes' => $tenant->setting('device_codes')];

        $tenant->update([
            'status' => $data['status'],
            'environment' => $data['environment'],
            'settlement_adapter' => $data['settlement_adapter'],
            'voice_mode' => $data['voice_mode'],
            'code_ttl_minutes' => $data['code_ttl_minutes'],
            'bind_caller' => $request->boolean('bind_caller'),
            'short_code' => $data['short_code'] ?? $tenant->short_code,
            'settings' => $settings,
        ]);

        $after = $tenant->fresh()->only(['status', 'environment', 'settlement_adapter', 'voice_mode', 'code_ttl_minutes', 'bind_caller']) + ['device_codes' => $tenant->fresh()->setting('device_codes')];
        $this->audit->record($tenant->id, 'admin.tenant_updated', 'admin', $request->user('admin')->id, Tenant::class, $tenant->id,
            'Tenant settings changed', ['before' => $before, 'after' => $after]);

        return back()->with('status', 'Saved.');
    }

    public function verifyAudit(Tenant $tenant): RedirectResponse
    {
        $broken = $this->audit->verifyChain($tenant->id);

        return back()->with('status', $broken === []
            ? 'Audit chain is intact.'
            : 'Audit chain is BROKEN at entries: ' . implode(', ', $broken) . '.');
    }

    /** Stop a tenant for good without deleting its history: no API, no portal, keys revoked, numbers off. */
    public function archive(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate(['confirm_slug' => ['required', 'in:' . $tenant->slug]], ['confirm_slug.in' => 'Type the tenant\'s identifier to confirm.']);

        $tenant->update(['status' => 'archived']);
        \App\Models\ApiKey::where('tenant_id', $tenant->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        VoiceNumber::where('tenant_id', $tenant->id)->update(['active' => false]);
        $this->audit->record($tenant->id, 'admin.tenant_archived', 'admin', $request->user('admin')->id, Tenant::class, $tenant->id, 'Tenant archived: keys revoked, numbers switched off');

        return redirect()->route('admin.tenants.index')->with('status', "{$tenant->name} archived.");
    }

    /** Back to suspended, never straight to active: someone must look before it takes payments again. */
    public function restore(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->status === 'archived', 409);
        $tenant->update(['status' => 'suspended']);
        $this->audit->record($tenant->id, 'admin.tenant_restored', 'admin', $request->user('admin')->id, Tenant::class, $tenant->id, 'Tenant restored as suspended');

        return redirect()->route('admin.tenants.show', $tenant)->with('status', 'Restored as suspended. Issue new keys and switch it to active when ready.');
    }

    /** Only an archived tenant with no payments at all can be deleted. */
    public function destroy(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate(['confirm_slug' => ['required', 'in:' . $tenant->slug]], ['confirm_slug.in' => 'Type the tenant\'s identifier to confirm.']);
        abort_unless($tenant->status === 'archived', 409, 'Archive the tenant first.');
        if (PaymentCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return back()->withErrors(['confirm_slug' => 'This tenant has payment history, which is kept. It stays archived.']);
        }

        $name = $tenant->name;
        \Illuminate\Support\Facades\DB::table('audit_chain_heads')->where('tenant_id', $tenant->id)->delete();
        $tenant->delete();

        return redirect()->route('admin.tenants.index')->with('status', "{$name} deleted.");
    }

    /** Switch every portal user of the tenant off (or back on) in one go, e.g. after a compromise. */
    public function bulkUsers(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:off,on']]);
        $on = $data['action'] === 'on';
        $n = \App\Models\TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', ! $on)->update(['is_active' => $on]);
        $this->audit->record($tenant->id, 'admin.portal_users_bulk', 'admin', $request->user('admin')->id, Tenant::class, $tenant->id, "{$n} portal user(s) switched " . ($on ? 'on' : 'off'));

        return back()->with('status', "{$n} portal user(s) switched " . ($on ? 'on' : 'off') . '.');
    }
}
