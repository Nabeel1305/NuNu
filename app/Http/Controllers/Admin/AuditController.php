<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Support\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Everything operators did on this console, and every sign-in (successful or not). */
class AuditController extends Controller
{
    private function query(Request $request)
    {
        return PlatformAuditLog::query()
            ->when($request->filled('operator'), fn ($q) => $q->where('admin_email', 'like', '%' . $request->input('operator') . '%'))
            ->when($request->filled('event'), fn ($q) => $q->where('event', 'like', $request->input('event') . '%'))
            ->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', (int) $request->input('tenant')))
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->input('outcome')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('id');
    }

    public function index(Request $request): View
    {
        return view('admin.audit.index', [
            'rows' => $this->query($request)->paginate(50)->withQueryString(),
            'tenants' => Tenant::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        PlatformAudit::record($request->user('admin'), 'admin.audit_exported', 'Exported the platform audit log', null, null, PlatformAudit::inputSummary($request));

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'when', 'operator', 'event', 'outcome', 'tenant_id', 'description', 'ip']);
            $this->query($request)->limit(50000)->each(function ($r) use ($out) {
                // A cell that starts with = + - @ is read as a formula by spreadsheets.
                $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
                fputcsv($out, [$r->id, $r->created_at, $safe($r->admin_email), $r->event, $r->outcome, $r->tenant_id, $safe($r->description), $r->ip]);
            });
            fclose($out);
        }, 'platform-audit-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
