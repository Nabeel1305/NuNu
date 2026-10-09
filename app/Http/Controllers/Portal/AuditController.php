<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $event = $request->validate(['event' => ['nullable', 'string', 'max:60']])['event'] ?? null;

        return view('portal.audit', [
            'rows' => AuditLog::where('tenant_id', $tenantId)->when($event, fn ($q, $v) => $q->where('event_type', $v))->latest('id')->paginate(30)->withQueryString(),
            'events' => AuditLog::where('tenant_id', $tenantId)->distinct()->orderBy('event_type')->pluck('event_type'),
            'event' => $event,
            'total' => AuditLog::where('tenant_id', $tenantId)->count(),
        ]);
    }

    public function verify(Request $request, AuditLogService $audit): RedirectResponse
    {
        $broken = $audit->verifyChain($request->attributes->get('tenant')->id);

        return back()->with($broken === [] ? 'status' : 'tamper', $broken === []
            ? 'Audit chain is intact: no entry has been changed or removed.'
            : 'Audit chain is BROKEN at entries: ' . implode(', ', $broken) . '. Contact the platform operator.');
    }
}
