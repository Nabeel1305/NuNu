<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Support\Mask;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsController extends Controller
{
    public const STATUSES = ['pending', 'settled', 'failed'];
    private const EXPORT_LIMIT = 50000;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        return view('portal.transactions.index', [
            'transactions' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'totals' => (clone $this->query($filters))->selectRaw('currency, count(*) as n, sum(amount_minor) as total')->groupBy('currency')->get(),
        ]);
    }

    public function show(string $uuid): View
    {
        $transaction = Transaction::with(['paymentCode.subscriber', 'paymentCode.merchant'])->where('uuid', $uuid)->firstOrFail();
        $code = $transaction->paymentCode;

        // Webhooks that told the tenant about this payment: they carry the transaction or code id.
        $deliveries = WebhookDelivery::with('endpoint')
            ->where('created_at', '>=', $code->created_at->copy()->subMinute())
            ->latest('id')->limit(300)->get()
            ->filter(fn ($d) => in_array($transaction->uuid, [data_get($d->payload, 'data.id'), data_get($d->payload, 'data.transaction_id')], true)
                || data_get($d->payload, 'data.code_id') === $code->uuid
                || data_get($d->payload, 'data.id') === $code->uuid)
            ->sortBy('id')->values();

        return view('portal.transactions.show', compact('transaction', 'code', 'deliveries'));
    }

    public function export(Request $request, TenantContext $context): StreamedResponse
    {
        $filters = $this->filters($request);
        $tenant = $request->attributes->get('tenant');
        $name = 'transactions-' . Str::slug($tenant->slug) . '-' . now()->format('Ymd-His') . '.csv';

        // The stream runs after the request's middleware has cleared the tenant, so pin it again.
        return response()->streamDownload(function () use ($filters, $tenant, $context) {
            $context->run($tenant, function () use ($filters) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['transaction_id', 'reference', 'status', 'amount', 'currency', 'merchant_reference', 'merchant_name', 'subscriber_reference', 'code_id', 'settlement_reference', 'failure_reason', 'created_at', 'settled_at']);

                $this->query($filters)->with(['paymentCode.subscriber', 'paymentCode.merchant'])->orderBy('id')->limit(self::EXPORT_LIMIT)
                    ->chunk(500, function ($rows) use ($out) {
                        foreach ($rows as $t) {
                            fputcsv($out, array_map([Mask::class, 'csv'], [
                                $t->uuid, $t->reference, $t->status, number_format($t->amount_minor / 100, 2, '.', ''), $t->currency,
                                $t->paymentCode?->merchant?->reference, $t->paymentCode?->merchant?->name, $t->paymentCode?->subscriber?->reference,
                                $t->paymentCode?->uuid, $t->settlement_reference, $t->failure_reason,
                                $t->created_at?->toIso8601String(), $t->settled_at?->toIso8601String(),
                            ]));
                        }
                    });
                fclose($out);
            });
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, ?string> */
    private function filters(Request $request): array
    {
        $in = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', self::STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'min' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'max' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'merchant' => ['nullable', 'string', 'max:191'],
            'subscriber' => ['nullable', 'string', 'max:191'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return array_map(fn ($v) => $v === '' ? null : $v, $in + array_fill_keys(['status', 'from', 'to', 'min', 'max', 'merchant', 'subscriber', 'q'], null));
    }

    private function query(array $f): Builder
    {
        $q = Transaction::query()->with(['paymentCode.subscriber', 'paymentCode.merchant']);

        $q->when($f['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'], fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
            ->when($f['to'], fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))
            ->when($f['min'], fn ($q, $v) => $q->where('amount_minor', '>=', Money::toMinor($v)))
            ->when($f['max'], fn ($q, $v) => $q->where('amount_minor', '<=', Money::toMinor($v)))
            ->when($f['merchant'], fn ($q, $v) => $q->whereHas('paymentCode.merchant', fn ($m) => $m->where('reference', $v)))
            ->when($f['subscriber'], fn ($q, $v) => $q->whereHas('paymentCode.subscriber', fn ($s) => $s->where('reference', $v)))
            ->when($f['q'], function ($q, $v) {
                $like = addcslashes($v, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('uuid', 'like', $like)->orWhere('settlement_reference', 'like', $like));
            });

        return $q;
    }
}
