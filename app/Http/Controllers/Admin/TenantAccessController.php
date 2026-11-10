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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'allowed_ips' => ['nullable', 'string', 'max:500'],
        ]);

        [$key, $plain] = ApiKey::issue($tenant, $data['name']);
        $key->update([
            'expires_at' => ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            'allowed_ips' => self::cleanIps($data['allowed_ips'] ?? null),
        ]);

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

    /** Change who may use a key and until when. */
    public function updateKey(Request $request, Tenant $tenant, ApiKey $key): RedirectResponse
    {
        abort_unless($key->tenant_id === $tenant->id, 404);
        $data = $request->validate(['expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'allowed_ips' => ['nullable', 'string', 'max:500'], 'clear_expiry' => ['nullable', 'boolean']]);

        $key->update([
            'allowed_ips' => self::cleanIps($data['allowed_ips'] ?? null),
            'expires_at' => $request->boolean('clear_expiry') ? null : (! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : $key->expires_at),
        ]);
        $this->record($request, $tenant, 'admin.key_restricted', "API key {$key->prefix} restrictions changed");

        return back()->with('status', 'Key updated.');
    }

    /** Issue a replacement and let the old key work for a grace period, so the tenant can switch without downtime. */
    public function rotateKey(Request $request, Tenant $tenant, ApiKey $key): RedirectResponse
    {
        abort_unless($key->tenant_id === $tenant->id && $key->revoked_at === null, 404);
        $data = $request->validate(['grace_hours' => ['nullable', 'integer', 'min:0', 'max:168']]);
        $grace = (int) ($data['grace_hours'] ?? 24);

        [$new, $plain] = ApiKey::issue($tenant, $key->name . ' (rotated)');
        $new->update(['allowed_ips' => $key->allowed_ips, 'expires_at' => $key->expires_at]);
        $grace === 0 ? $key->update(['revoked_at' => now()]) : $key->update(['expires_at' => now()->addHours($grace)]);

        $this->record($request, $tenant, 'admin.key_rotated', "API key {$key->prefix} rotated to {$new->prefix}; old key " . ($grace === 0 ? 'revoked now' : "works for {$grace}h more"));

        return back()->with('secret', ['label' => "Replacement for key {$key->prefix} (the old key " . ($grace === 0 ? 'is revoked' : "stops in {$grace}h") . ')', 'value' => $plain]);
    }

    public function revokeAllKeys(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate(['confirm_slug' => ['required', 'in:' . $tenant->slug]], ['confirm_slug.in' => 'Type the tenant\'s identifier to confirm.']);
        $n = ApiKey::where('tenant_id', $tenant->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->record($request, $tenant, 'admin.keys_revoked_all', "{$n} API key(s) revoked");

        return back()->with('status', "{$n} key(s) revoked.");
    }

    /** Comma-separated list of IPv4/IPv6 addresses, or null. Invalid entries are rejected, not dropped. */
    private static function cleanIps(?string $raw): ?string
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
        foreach ($list as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['allowed_ips' => "“{$ip}” is not an IP address."]);
            }
        }

        return $list === [] ? null : implode(',', $list);
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

    /** Move a number to another tenant, or back to the shared pool. */
    public function reassignNumber(Request $request, Tenant $tenant, VoiceNumber $number): RedirectResponse
    {
        abort_unless($number->tenant_id === $tenant->id, 404);
        $data = $request->validate(['to' => ['required', 'string']]);
        $target = $data['to'] === 'pool' ? null : Tenant::findOrFail((int) $data['to']);

        $number->update(['tenant_id' => $target?->id]);
        $this->record($request, $tenant, 'admin.number_reassigned', "Voice number {$number->number} moved to " . ($target?->name ?? 'the shared pool'));
        if ($target) {
            $this->record($request, $target, 'admin.number_reassigned', "Voice number {$number->number} moved here from {$tenant->name}");
        }

        return back()->with('status', 'Number moved.');
    }

    public function destroyNumber(Request $request, Tenant $tenant, VoiceNumber $number): RedirectResponse
    {
        abort_unless($number->tenant_id === $tenant->id, 404);
        $number->delete();
        $this->record($request, $tenant, 'admin.number_deleted', "Voice number {$number->number} deleted");

        return back()->with('status', 'Number deleted. Its callback URL no longer works.');
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
