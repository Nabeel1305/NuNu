<?php

namespace App\Services\Portal;

use App\Models\Merchant;
use App\Models\PaymentCode;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\Codes\CodeState;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the tenant dashboard. Every query goes through a tenant-scoped model (the portal
 * middleware pins TenantContext), except the one join, which names the tenant explicitly.
 */
class PortalStats
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    /** @return array<string, mixed> */
    public function money(CarbonInterface $from, int $chartDays): array
    {
        $codes = PaymentCode::where('created_at', '>=', $from)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');

        $settled = Transaction::where('status', 'settled')->where('settled_at', '>=', $from)
            ->selectRaw('currency, count(*) as n, sum(amount_minor) as total')->groupBy('currency')->get();

        $counts = Transaction::where('created_at', '>=', $from)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $done = ($counts['settled'] ?? 0) + ($counts['failed'] ?? 0);

        $pending = Transaction::where('status', 'pending');

        return [
            'codeCounts' => $codes,
            'codesIssued' => (int) $codes->sum(),
            'openCodes' => PaymentCode::where('state', CodeState::Issued->value)->where('expires_at', '>', now())->count(),
            'settled' => $settled,
            'settledCount' => (int) $settled->sum('n'),
            'successRate' => $done > 0 ? round((($counts['settled'] ?? 0) / $done) * 100, 1) : null,
            'failedCount' => (int) ($counts['failed'] ?? 0),
            'pendingCount' => (clone $pending)->count(),
            'oldestPending' => (clone $pending)->min('created_at'),
            'recentFailures' => Transaction::where('status', 'failed')->latest('id')->limit(5)->get(),
            'chart' => $this->chart($chartDays),
            'topMerchants' => $this->topMerchants($from),
        ];
    }

    /** @return array{labels: list<string>, amounts: list<float>, counts: list<int>, currency: string|null} */
    private function chart(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        // Chart the tenant's busiest currency; mixing currencies on one axis would mislead.
        $currency = Transaction::where('status', 'settled')->where('settled_at', '>=', $start)
            ->selectRaw('currency, count(*) as n')->groupBy('currency')->orderByDesc('n')->value('currency');

        $rows = $currency === null ? collect() : Transaction::where('status', 'settled')->where('currency', $currency)
            ->where('settled_at', '>=', $start)
            ->selectRaw('DATE(settled_at) as d, count(*) as n, sum(amount_minor) as total')->groupBy('d')->get()->keyBy('d');

        $labels = $amounts = $counts = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $row = $rows->get($day->toDateString());
            $labels[] = $day->format('M j');
            $amounts[] = $row ? round($row->total / 100, 2) : 0;
            $counts[] = $row ? (int) $row->n : 0;
        }

        return compact('labels', 'amounts', 'counts', 'currency');
    }

    private function topMerchants(CarbonInterface $from)
    {
        $tenantId = $this->context->id();

        return DB::table('transactions as t')
            ->join('payment_codes as c', 'c.id', '=', 't.payment_code_id')
            ->join('merchants as m', 'm.id', '=', 'c.merchant_id')
            ->where('t.tenant_id', $tenantId)->where('c.tenant_id', $tenantId)->where('m.tenant_id', $tenantId)
            ->where('t.status', 'settled')->where('t.settled_at', '>=', $from)
            ->groupBy('m.id', 'm.name', 'm.reference', 't.currency')
            ->orderByDesc(DB::raw('sum(t.amount_minor)'))
            ->limit(5)
            ->get(['m.name', 'm.reference', 't.currency', DB::raw('count(*) as n'), DB::raw('sum(t.amount_minor) as total')]);
    }

    /** @return array{delivered:int, failed:int, pending:int, total:int, rate:float|null} */
    public function deliveries(CarbonInterface $from): array
    {
        $by = WebhookDelivery::where('created_at', '>=', $from)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $delivered = (int) ($by['delivered'] ?? 0);
        $failed = (int) ($by['failed'] ?? 0);

        return [
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => (int) ($by['pending'] ?? 0),
            'total' => (int) $by->sum(),
            'rate' => ($delivered + $failed) > 0 ? round($delivered / ($delivered + $failed) * 100, 1) : null,
        ];
    }
}
