<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PaymentCode;
use App\Models\TenantUser;
use App\Services\Audit\AuditLogService;
use App\Services\Codes\CodeService;
use App\Services\Codes\CodeState;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Payment codes: state and expiry only. The code itself is never stored, so it can never be shown. */
class CodesController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'state' => ['nullable', 'in:' . implode(',', array_column(CodeState::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]) + ['state' => null, 'from' => null, 'to' => null, 'q' => null];

        $codes = PaymentCode::with(['subscriber', 'merchant', 'transaction'])
            ->when($f['state'], fn ($q, $v) => $q->where('state', $v))
            ->when($f['from'], fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
            ->when($f['to'], fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))
            ->when($f['q'], function ($q, $v) {
                $like = addcslashes($v, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('uuid', 'like', $like)
                    ->orWhereHas('subscriber', fn ($s) => $s->where('reference', 'like', $like))
                    ->orWhereHas('merchant', fn ($m) => $m->where('reference', 'like', $like)));
            })
            ->latest('id')->paginate(25)->withQueryString();

        return view('portal.codes.index', ['codes' => $codes, 'filters' => $f, 'states' => CodeState::cases()]);
    }

    public function show(string $uuid): View
    {
        $code = PaymentCode::with(['subscriber', 'merchant', 'transaction'])->where('uuid', $uuid)->firstOrFail();

        return view('portal.codes.show', ['code' => $code]);
    }

    public function cancel(Request $request, string $uuid, CodeService $codes, AuditLogService $audit): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $user = $request->user('tenant');

        try {
            $code = $codes->cancel($tenant, $uuid);
        } catch (\LogicException) {
            return back()->withErrors(['code' => 'Only a code nobody has dialled yet can be cancelled.']);
        }

        $audit->record($tenant->id, 'portal.code_cancelled', 'tenant_user', $user->id, PaymentCode::class, $code->id,
            "{$user->email} cancelled a code for " . Money::format($code->amount_minor, $code->currency), [], $code->uuid);

        return redirect()->route('portal.codes.show', $uuid)->with('status', 'Code cancelled and the hold released.');
    }
}
