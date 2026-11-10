<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** One box that finds a reference, uuid, phone, account number, device or tenant across every tenant. */
class SearchController extends Controller
{
    public function index(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) > 80, 422);
        if (mb_strlen($q) < 3) {
            return view('admin.search.index', ['q' => $q, 'groups' => [], 'short' => $q !== '']);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $tx = DB::table('transactions as t')->join('tenants as n', 'n.id', '=', 't.tenant_id')
            ->where(fn ($w) => $w->where('t.reference', 'like', $like)->orWhere('t.uuid', 'like', $like))
            ->select('t.uuid', 't.reference', 't.status', 't.amount_minor', 't.currency', 'n.name as tenant')->orderByDesc('t.id')->limit(10)->get();

        if ($tx->count() === 1 && strcasecmp($tx->first()->reference, $q) === 0) {
            return redirect()->route('admin.payments.show', $tx->first()->uuid);
        }

        $codes = DB::table('payment_codes as c')->join('transactions as t', 't.payment_code_id', '=', 'c.id')->join('tenants as n', 'n.id', '=', 'c.tenant_id')
            ->where('c.uuid', 'like', $like)->select('t.uuid as tx_uuid', 'c.uuid', 'c.state', 'n.name as tenant')->limit(10)->get();

        $subs = DB::table('subscribers as s')->join('tenants as n', 'n.id', '=', 's.tenant_id')
            ->where(fn ($w) => $w->where('s.reference', 'like', $like)->orWhere('s.phone', 'like', $like)->orWhere('s.phone_normalized', 'like', $like))
            ->select('s.reference', 's.phone', 'n.id as tenant_id', 'n.name as tenant')->limit(10)->get();

        $merchants = DB::table('merchants as m')->join('tenants as n', 'n.id', '=', 'm.tenant_id')
            ->where(fn ($w) => $w->where('m.reference', 'like', $like)->orWhere('m.name', 'like', $like)->orWhere('m.account_number', 'like', $like)->orWhere('m.short_id', $q))
            ->select('m.reference', 'm.name', 'm.account_number', 'm.bank_code', 'n.id as tenant_id', 'n.name as tenant')->limit(10)->get();

        $devices = DB::table('offline_devices as d')->join('tenants as n', 'n.id', '=', 'd.tenant_id')->join('subscribers as s', 's.id', '=', 'd.subscriber_id')
            ->where(fn ($w) => $w->where('d.short_id', $q)->orWhere('d.device_id', 'like', $like))
            ->select('d.id', 'd.short_id', 'd.status', 's.reference as subscriber', 'n.name as tenant')->limit(10)->get();

        $tenants = DB::table('tenants')->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like))->select('id', 'name', 'slug', 'status')->limit(10)->get();

        return view('admin.search.index', ['q' => $q, 'short' => false, 'groups' => compact('tx', 'codes', 'subs', 'merchants', 'devices', 'tenants')]);
    }
}
