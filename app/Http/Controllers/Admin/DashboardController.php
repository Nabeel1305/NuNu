<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/** The platform at a glance: volume, success, what is stuck, what is failing. */
class DashboardController extends Controller
{
    private function window(\Illuminate\Support\Carbon $since): object
    {
        return DB::table('transactions as t')->join('payment_codes as c', 'c.id', '=', 't.payment_code_id')
            ->where('t.created_at', '>=', $since)
            ->selectRaw("count(*) as total, coalesce(sum(case when t.status = 'settled' then 1 else 0 end), 0) as settled, "
                . "coalesce(sum(case when t.status = 'failed' then 1 else 0 end), 0) as failed, "
                . "coalesce(sum(case when t.status = 'pending' then 1 else 0 end), 0) as pending, "
                . "coalesce(sum(case when t.status = 'settled' then t.amount_minor else 0 end), 0) as volume, "
                . "coalesce(sum(case when c.origin = 'device' then 1 else 0 end), 0) as offline")
            ->first();
    }

    public function __invoke(): View
    {
        $windows = ['24 hours' => now()->subDay(), '7 days' => now()->subDays(7), '30 days' => now()->subDays(30)];
        $stats = [];
        foreach ($windows as $label => $since) {
            $w = $this->window($since);
            $done = $w->settled + $w->failed;
            $stats[$label] = ['total' => (int) $w->total, 'settled' => (int) $w->settled, 'failed' => (int) $w->failed, 'pending' => (int) $w->pending,
                'volume' => (int) $w->volume, 'offline' => (int) $w->offline, 'rate' => $done > 0 ? round($w->settled / $done * 100, 1) : null,
                'offline_share' => $w->total > 0 ? round($w->offline / $w->total * 100) : null];
        }

        $top = DB::table('transactions as t')->join('tenants as n', 'n.id', '=', 't.tenant_id')
            ->where('t.created_at', '>=', now()->subDays(30))
            ->selectRaw("n.id, n.name, count(*) as total, coalesce(sum(case when t.status = 'settled' then t.amount_minor else 0 end), 0) as volume, "
                . "coalesce(sum(case when t.status = 'failed' then 1 else 0 end), 0) as failed")
            ->groupBy('n.id', 'n.name')->orderByDesc('volume')->limit(8)->get();

        return view('admin.dashboard.index', [
            'stats' => $stats, 'top' => $top,
            'stuck' => DB::table('transactions')->where('status', 'pending')->where('created_at', '<=', now()->subMinutes((int) config('platform.reconcile.after_minutes')))->count(),
            'backlog' => DB::table('webhook_deliveries')->where('status', 'pending')->count(),
            'failedDeliveries' => DB::table('webhook_deliveries')->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'tenantCounts' => Tenant::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'devices' => DB::table('offline_devices')->where('status', 'active')->count(),
            'failedJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
        ]);
    }
}
