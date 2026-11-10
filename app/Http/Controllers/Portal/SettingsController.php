<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\VoiceNumber;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What the platform operator has configured for this tenant, and the few choices the bank makes for itself. */
class SettingsController extends Controller
{
    /**
     * The bank's own switch for paying an account the phone has never looked up, and its cap for those payments.
     * The cap can never be above the overall offline cap the operator set.
     */
    public function updateDirectCodes(Request $request, \App\Services\Audit\AuditLogService $audit): \Illuminate\Http\RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $current = \App\Services\Offline\DeviceCodeSettings::for($tenant);
        abort_unless($current->enabled, 403, 'Offline phone codes are not switched on for your organisation.');

        $data = $request->validate([
            'direct_max_naira' => ['nullable', 'numeric', 'min:1', 'max:' . ($current->maxAmountMinor / 100)],
        ]);

        $settings = $tenant->settings ?? [];
        $before = $settings['device_codes'] ?? [];
        $device = $before;
        $device['direct_enabled'] = $request->boolean('direct_enabled');
        if (($data['direct_max_naira'] ?? null) !== null && $data['direct_max_naira'] !== '') {
            $device['direct_max_amount_minor'] = (int) round(((float) $data['direct_max_naira']) * 100);
        }
        $settings['device_codes'] = $device;
        $tenant->update(['settings' => $settings]);

        $audit->record($tenant->id, 'tenant.direct_codes_changed', 'tenant_user', $request->user('tenant')->id, \App\Models\Tenant::class, $tenant->id,
            'Payments to accounts never looked up: ' . ($device['direct_enabled'] ? 'on' : 'off'),
            ['before' => $before, 'after' => $device]);

        return back()->with('status', 'Saved.');
    }

    public function __invoke(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $max = $tenant->setting('max_amount_minor');

        return view('portal.settings', [
            'numbers' => VoiceNumber::where('tenant_id', $tenant->id)->orderBy('number')->get(),
            'maxAmount' => $max ? Money::format($max, 'NGN') : null,
            'deviceCodes' => \App\Services\Offline\DeviceCodeSettings::for($tenant),
            'apiBase' => url('/api/v1'),
        ]);
    }
}
