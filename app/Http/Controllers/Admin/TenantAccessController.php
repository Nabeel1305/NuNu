<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\VoiceNumber;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** API keys and voice numbers for one tenant. Secrets are shown once, in a flash, and never stored readable. */
class TenantAccessController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function issueKey(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        [$key, $plain] = ApiKey::issue($tenant, $data['name']);

        $this->record($request, $tenant, 'admin.key_issued', "API key {$key->prefix} issued");

        return back()->with('secret', ['label' => 'API key', 'value' => $plain]);
    }

    public function revokeKey(Request $request, Tenant $tenant, ApiKey $key): RedirectResponse
    {
        abort_unless($key->tenant_id === $tenant->id, 404);

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm the revoke.']);

        $key->update(['revoked_at' => $key->revoked_at ?? now()]);

        $this->record($request, $tenant, 'admin.key_revoked', "API key {$key->prefix} revoked");

        return back()->with('status', 'Key revoked.');
    }

    public function addNumber(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['number' => ['required', 'regex:/^\+?\d{7,15}$/', 'unique:voice_numbers,number']]);

        $token = bin2hex(random_bytes(24));

        VoiceNumber::create([
            'tenant_id' => $tenant->id,
            'number' => $data['number'],
            'webhook_token_hash' => hash('sha256', $token),
        ]);

        $this->record($request, $tenant, 'admin.number_added', "Voice number {$data['number']} added");

        return back()->with('secret', [
            'label' => 'Callback URL for ' . $data['number'],
            'value' => url('/api/voice/africastalking') . '?token=' . $token,
        ]);
    }

    public function toggleNumber(Request $request, Tenant $tenant, VoiceNumber $number): RedirectResponse
    {
        abort_unless($number->tenant_id === $tenant->id, 404);

        $number->update(['active' => ! $number->active]);

        $this->record($request, $tenant, 'admin.number_toggled', "Voice number {$number->number} " . ($number->active ? 'enabled' : 'disabled'));

        return back()->with('status', 'Updated.');
    }

    /** Replace a number's callback token; the old URL stops working immediately. */
    public function rotateNumberToken(Request $request, Tenant $tenant, VoiceNumber $number): RedirectResponse
    {
        abort_unless($number->tenant_id === $tenant->id, 404);

        $token = bin2hex(random_bytes(24));
        $number->update(['webhook_token_hash' => hash('sha256', $token)]);

        $this->record($request, $tenant, 'admin.number_token_rotated', "Callback token for {$number->number} rotated");

        return back()->with('secret', [
            'label' => 'New callback URL for ' . $number->number,
            'value' => url('/api/voice/africastalking') . '?token=' . $token,
        ]);
    }

    private function record(Request $request, Tenant $tenant, string $event, string $description): void
    {
        $this->audit->record($tenant->id, $event, 'admin', $request->user('admin')->id, Tenant::class, $tenant->id, $description);
    }
}
