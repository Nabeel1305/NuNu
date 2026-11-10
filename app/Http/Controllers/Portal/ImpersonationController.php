<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogService;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function stop(Request $request, AuditLogService $audit): RedirectResponse
    {
        $imp = Impersonation::current($request);
        Impersonation::end($request, $audit, 'stopped by the operator');

        return $imp
            ? redirect()->route('admin.tenants.show', $imp['tenant_id'])->with('status', 'Stopped viewing as the portal user.')
            : redirect()->route('portal.login');
    }
}
