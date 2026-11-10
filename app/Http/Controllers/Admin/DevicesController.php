<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfflineDevice;
use App\Models\Tenant;
use App\Services\Audit\AuditLogService;
use App\Support\PlatformAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Phones enrolled for offline payments, across tenants. An operator can switch one off at once. */
class DevicesController extends Controller
{
    private function query(Request $request): Builder
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return DB::table('offline_devices as d')->join('tenants as n', 'n.id', '=', 'd.tenant_id')->join('subscribers as s', 's.id', '=', 'd.subscriber_id')
            ->select('d.id', 'd.short_id', 'd.status', 'd.key_version', 'd.last_counter', 'd.last_used_at', 'd.created_at', 'd.revoked_at', 's.reference as subscriber', 's.phone', 'n.id as tenant_id', 'n.name as tenant')
            ->when($request->filled('tenant'), fn ($b) => $b->where('d.tenant_id', (int) $request->query('tenant')))
            ->when($request->filled('status'), fn ($b) => $b->where('d.status', $request->query('status') === 'revoked' ? 'revoked' : 'active'))
            ->when($request->boolean('idle'), fn ($b) => $b->where(fn ($w) => $w->whereNull('d.last_used_at')->orWhere('d.last_used_at', '<', now()->subDays(30))))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('d.short_id', $q)->orWhere('s.reference', 'like', $like)->orWhere('s.phone', 'like', $like)))
            ->orderByDesc('d.id');
    }

    public function index(Request $request): View
    {
        return view('admin.devices.index', ['rows' => $this->query($request)->paginate(40)->withQueryString(), 'tenants' => Tenant::orderBy('name')->pluck('name', 'id')]);
    }

    public function export(Request $request): StreamedResponse
    {
        PlatformAudit::record($request->user('admin'), 'admin.devices_exported', 'Exported offline devices to CSV');

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['tenant', 'subscriber', 'phone_last4', 'short_id', 'status', 'key_version', 'last_counter', 'last_used_at', 'enrolled_at', 'revoked_at']);
            foreach ($this->query($request)->limit(50000)->get() as $r) {
                fputcsv($out, [PaymentsController::csvSafe($r->tenant), PaymentsController::csvSafe($r->subscriber), substr(preg_replace('/\D/', '', (string) $r->phone), -4),
                    $r->short_id, $r->status, $r->key_version, $r->last_counter, $r->last_used_at, $r->created_at, $r->revoked_at]);
            }
            fclose($out);
        }, 'devices-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function revoke(Request $request, int $device, AuditLogService $audit): RedirectResponse
    {
        $model = OfflineDevice::withoutGlobalScopes()->findOrFail($device);
        $request->attributes->set('audit_tenant_id', $model->tenant_id);

        if ($model->status === 'active') {
            $model->update(['status' => 'revoked', 'revoked_at' => now()]);
            $admin = $request->user('admin');
            $audit->record($model->tenant_id, 'device.revoked', 'admin', $admin->id, OfflineDevice::class, $model->id,
                "Phone {$model->short_id} switched off by operator {$admin->email}", ['short_id' => $model->short_id], $model->short_id);
        }

        return back()->with('status', "Phone {$model->short_id} switched off. Its codes stop working at once.");
    }
}
