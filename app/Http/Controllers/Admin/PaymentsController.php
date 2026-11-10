<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentCode;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\Codes\CodeService;
use App\Support\PlatformAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Every tenant's payments in one place: find, inspect, re-check, close. */
class PaymentsController extends Controller
{
    public static function csvSafe(mixed $v): mixed
    {
        // A cell that starts with = + - @ is read as a formula by spreadsheets.
        return is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private function query(Request $request): Builder
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:pending,settled,failed'], 'tenant' => ['nullable', 'integer'],
            'origin' => ['nullable', 'in:issued,device'], 'q' => ['nullable', 'string', 'max:80'],
            'min' => ['nullable', 'numeric', 'min:0'], 'max' => ['nullable', 'numeric', 'min:0'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'stuck' => ['nullable', 'boolean'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return DB::table('transactions as t')
            ->join('payment_codes as c', 'c.id', '=', 't.payment_code_id')
            ->join('tenants as n', 'n.id', '=', 't.tenant_id')
            ->join('subscribers as s', 's.id', '=', 'c.subscriber_id')
            ->join('merchants as m', 'm.id', '=', 'c.merchant_id')
            ->select('t.id', 't.uuid', 't.reference', 't.amount_minor', 't.currency', 't.status', 't.failure_reason', 't.created_at', 't.settled_at',
                'n.id as tenant_id', 'n.name as tenant', 'c.origin', 'c.destination_account_number as to_account', 'c.destination_bank_code as to_bank',
                's.reference as subscriber', 's.phone', 'm.reference as merchant')
            ->when($f['status'] ?? null, fn ($b, $v) => $b->where('t.status', $v))
            ->when($f['tenant'] ?? null, fn ($b, $v) => $b->where('t.tenant_id', $v))
            ->when($f['origin'] ?? null, fn ($b, $v) => $b->where('c.origin', $v))
            ->when(isset($f['min']) && $f['min'] !== null, fn ($b) => $b->where('t.amount_minor', '>=', (int) round($f['min'] * 100)))
            ->when(isset($f['max']) && $f['max'] !== null, fn ($b) => $b->where('t.amount_minor', '<=', (int) round($f['max'] * 100)))
            ->when($f['from'] ?? null, fn ($b, $v) => $b->where('t.created_at', '>=', \Illuminate\Support\Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($b, $v) => $b->where('t.created_at', '<=', \Illuminate\Support\Carbon::parse($v)->endOfDay()))
            ->when($request->boolean('stuck'), fn ($b) => $b->where('t.status', 'pending')->where('t.created_at', '<=', now()->subMinutes((int) config('platform.reconcile.after_minutes'))))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('t.reference', 'like', $like)->orWhere('t.uuid', 'like', $like)
                ->orWhere('s.phone', 'like', $like)->orWhere('s.reference', 'like', $like)->orWhere('m.reference', 'like', $like)
                ->orWhere('c.destination_account_number', 'like', $like)->orWhere('c.source_account_number', 'like', $like)))
            ->orderByDesc('t.id');
    }

    public function index(Request $request): View
    {
        return view('admin.payments.index', [
            'rows' => $this->query($request)->paginate(40)->withQueryString(),
            'tenants' => Tenant::orderBy('name')->pluck('name', 'id'),
            'stuckCount' => Transaction::withoutGlobalScopes()->where('status', 'pending')->where('created_at', '<=', now()->subMinutes((int) config('platform.reconcile.after_minutes')))->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        PlatformAudit::record($request->user('admin'), 'admin.payments_exported', 'Exported payments to CSV', null, null, PlatformAudit::inputSummary($request));

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['reference', 'tenant', 'status', 'amount', 'currency', 'origin', 'payer', 'phone_last4', 'to_bank', 'to_account_last4', 'failure_reason', 'created_at', 'settled_at']);
            foreach ($this->query($request)->limit(50000)->get() as $r) {
                fputcsv($out, [$r->reference, self::csvSafe($r->tenant), $r->status, number_format($r->amount_minor / 100, 2, '.', ''), $r->currency, $r->origin,
                    self::csvSafe($r->subscriber), substr(preg_replace('/\D/', '', (string) $r->phone), -4), $r->to_bank, substr((string) $r->to_account, -4), self::csvSafe($r->failure_reason), $r->created_at, $r->settled_at]);
            }
            fclose($out);
        }, 'payments-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(string $uuid): View
    {
        $tx = Transaction::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $code = PaymentCode::withoutGlobalScopes()->with(['subscriber' => fn ($q) => $q->withoutGlobalScopes(), 'merchant' => fn ($q) => $q->withoutGlobalScopes()])->findOrFail($tx->payment_code_id);
        $tenant = Tenant::findOrFail($tx->tenant_id);

        return view('admin.payments.show', [
            'tx' => $tx, 'code' => $code, 'tenant' => $tenant,
            'trail' => AuditLog::where('tenant_id', $tenant->id)->where(fn ($q) => $q->where('reference', $tx->reference)->orWhere('reference', $code->uuid))->orderBy('id')->get(),
            'deliveries' => WebhookDelivery::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('payload->data->reference', $tx->reference)->latest('id')->limit(10)->get(),
            'stuckAfter' => (int) config('platform.reconcile.after_minutes'),
        ]);
    }

    public function recheck(string $uuid, CodeService $codes): RedirectResponse
    {
        $tx = Transaction::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        abort_if($tx->status !== 'pending', 409, 'It is not pending.');
        request()->attributes->set('audit_tenant_id', $tx->tenant_id);

        $result = $codes->recheck($tx);
        $words = ['settled' => 'Settled: the tenant had captured it.', 'failed' => 'Failed: the tenant had released or declined it.',
            'retried' => 'The tenant has not captured it yet; capture was asked again.', 'flagged' => 'No clear answer from the tenant. Flagged for review.',
            'unchanged' => 'Still pending: nothing to act on yet.'];

        return back()->with('status', $words[$result] ?? $result);
    }

    public function markFailed(Request $request, string $uuid, CodeService $codes): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $tx = Transaction::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $request->attributes->set('audit_tenant_id', $tx->tenant_id);

        return back()->with('status', $codes->markFailed($tx, $data['reason']));
    }

    public function cancelCode(Request $request, string $uuid, CodeService $codes): RedirectResponse
    {
        $code = PaymentCode::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $tenant = Tenant::findOrFail($code->tenant_id);
        $admin = $request->user('admin');
        $request->attributes->set('audit_tenant_id', $tenant->id);

        try {
            $codes->cancel($tenant, $uuid, 'admin', $admin->id, "Code cancelled by operator {$admin->email}");
        } catch (\Throwable $e) {
            return back()->withErrors(['code' => 'That code can no longer be cancelled.']);
        }

        return back()->with('status', 'Code cancelled and its hold released.');
    }
}
