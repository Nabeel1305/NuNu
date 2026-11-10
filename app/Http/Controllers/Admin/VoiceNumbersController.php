<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceNumber;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Every voice number on the platform: the shared pool and each tenant's own. Look at them all here, add one to the
 * pool or to a tenant, change a number, switch it off, move it, delete it, replace its callback token.
 */
class VoiceNumbersController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function index(Request $request): View
    {
        $rows = DB::table('voice_numbers as v')->leftJoin('tenants as n', 'n.id', '=', 'v.tenant_id')
            ->select('v.id', 'v.number', 'v.provider', 'v.active', 'v.tenant_id', 'v.created_at', 'n.name as tenant', 'n.voice_mode')
            ->when($request->query('owner') === 'pool', fn ($b) => $b->whereNull('v.tenant_id'))
            ->when(ctype_digit((string) $request->query('owner')), fn ($b) => $b->where('v.tenant_id', (int) $request->query('owner')))
            ->orderByRaw('v.tenant_id is not null')->orderBy('v.number')->get();

        return view('admin.voice.index', [
            'rows' => $rows,
            'tenants' => Tenant::where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name', 'voice_mode']),
            // A shared-mode tenant with no active pool number cannot give its payers a number to ring.
            'sharedWithoutNumber' => DB::table('voice_numbers')->whereNull('tenant_id')->where('active', true)->doesntExist()
                ? Tenant::where('voice_mode', 'shared')->where('status', 'active')->pluck('name') : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'regex:/^\+?\d{7,15}$/', 'unique:voice_numbers,number'],
            'owner' => ['required', 'string'],
        ]);
        $tenant = $data['owner'] === 'pool' ? null : Tenant::findOrFail((int) $data['owner']);
        $token = bin2hex(random_bytes(24));

        $n = VoiceNumber::create(['tenant_id' => $tenant?->id, 'number' => $data['number'], 'webhook_token_hash' => hash('sha256', $token)]);
        $this->note($request, $n, 'admin.number_added', "Voice number {$n->number} added" . ($tenant ? '' : ' to the shared pool'));

        return back()->with('secret', ['label' => 'Callback URL for ' . $n->number . ' (paste it into the provider\'s dashboard)', 'value' => url('/api/voice/africastalking') . '?token=' . $token]);
    }

    /** Change the number itself. The callback token stays, so only the provider's routing has to follow. */
    public function update(Request $request, VoiceNumber $number): RedirectResponse
    {
        $data = $request->validate(['number' => ['required', 'regex:/^\+?\d{7,15}$/', Rule::unique('voice_numbers', 'number')->ignore($number->id)]]);
        $old = $number->number;

        if ($old !== $data['number']) {
            $number->update(['number' => $data['number']]);
            $this->note($request, $number, 'admin.number_changed', "Voice number changed from {$old} to {$number->number}");
        }

        return back()->with('status', "Number is now {$number->number}. Make sure the provider sends calls to it on the same callback URL.");
    }

    public function toggle(Request $request, VoiceNumber $number): RedirectResponse
    {
        $number->update(['active' => ! $number->active]);
        $this->note($request, $number, 'admin.number_toggled', "Voice number {$number->number} " . ($number->active ? 'enabled' : 'disabled'));

        return back()->with('status', 'Updated.');
    }

    public function rotate(Request $request, VoiceNumber $number): RedirectResponse
    {
        $token = bin2hex(random_bytes(24));
        $number->update(['webhook_token_hash' => hash('sha256', $token)]);
        $this->note($request, $number, 'admin.number_token_rotated', "Callback token for {$number->number} rotated");

        return back()->with('secret', ['label' => 'New callback URL for ' . $number->number, 'value' => url('/api/voice/africastalking') . '?token=' . $token]);
    }

    public function reassign(Request $request, VoiceNumber $number): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);
        $target = $data['to'] === 'pool' ? null : Tenant::findOrFail((int) $data['to']);
        $from = $number->tenant_id ? Tenant::find($number->tenant_id) : null;

        $number->update(['tenant_id' => $target?->id]);
        $this->note($request, $number, 'admin.number_reassigned', "Voice number {$number->number} moved to " . ($target?->name ?? 'the shared pool'), $from?->id);

        return back()->with('status', 'Number moved.');
    }

    public function destroy(Request $request, VoiceNumber $number): RedirectResponse
    {
        $this->note($request, $number, 'admin.number_deleted', "Voice number {$number->number} deleted");
        $number->delete();

        return back()->with('status', 'Number deleted. Its callback URL no longer works.');
    }

    /** Written to the tenant's own audit log when it has one, and tagged for the platform audit log either way. */
    private function note(Request $request, VoiceNumber $number, string $event, string $description, ?int $alsoTenant = null): void
    {
        $request->attributes->set('audit_tenant_id', $number->tenant_id ?? $alsoTenant);
        foreach (array_unique(array_filter([$number->tenant_id, $alsoTenant])) as $tenantId) {
            $this->audit->record($tenantId, $event, 'admin', $request->user('admin')->id, VoiceNumber::class, $number->id, $description);
        }
    }
}
