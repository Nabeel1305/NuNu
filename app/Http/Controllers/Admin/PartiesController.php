<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Payers (subscribers) and recipients (merchants) across tenants. Read-only. */
class PartiesController extends Controller
{
    public function subscribers(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $rows = DB::table('subscribers as s')->join('tenants as n', 'n.id', '=', 's.tenant_id')
            ->selectRaw('s.id, s.reference, s.phone, s.account_number, s.bank_code, s.created_at, n.id as tenant_id, n.name as tenant, '
                . '(select count(*) from payment_codes c where c.subscriber_id = s.id) as codes, (select count(*) from offline_devices d where d.subscriber_id = s.id and d.status = \'active\') as phones')
            ->when($request->filled('tenant'), fn ($b) => $b->where('s.tenant_id', (int) $request->query('tenant')))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('s.reference', 'like', $like)->orWhere('s.phone', 'like', $like)->orWhere('s.account_number', 'like', $like)))
            ->orderByDesc('s.id')->paginate(40)->withQueryString();

        return view('admin.parties.subscribers', ['rows' => $rows, 'tenants' => Tenant::orderBy('name')->pluck('name', 'id')]);
    }

    public function merchants(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $rows = DB::table('merchants as m')->join('tenants as n', 'n.id', '=', 'm.tenant_id')
            ->selectRaw('m.id, m.reference, m.name, m.short_id, m.account_number, m.bank_code, m.created_at, n.id as tenant_id, n.name as tenant, '
                . '(select count(*) from payment_codes c where c.merchant_id = m.id) as codes')
            ->when($request->filled('tenant'), fn ($b) => $b->where('m.tenant_id', (int) $request->query('tenant')))
            ->when($request->boolean('direct'), fn ($b) => $b->where('m.reference', 'like', 'direct-%'))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('m.reference', 'like', $like)->orWhere('m.name', 'like', $like)->orWhere('m.account_number', 'like', $like)))
            ->orderByDesc('m.id')->paginate(40)->withQueryString();

        return view('admin.parties.merchants', ['rows' => $rows, 'tenants' => Tenant::orderBy('name')->pluck('name', 'id')]);
    }
}
