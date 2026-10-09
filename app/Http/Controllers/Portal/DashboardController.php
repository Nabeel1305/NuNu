<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\VoiceNumber;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Portal\PortalStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const PERIODS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'];

    public function __invoke(Request $request, PortalStats $stats): View
    {
        $user = $request->user('tenant');
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : '7d';
        $from = match ($period) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(6)->startOfDay(),
            '30d' => now()->subDays(29)->startOfDay(),
            '90d' => now()->subDays(89)->startOfDay(),
        };

        $data = ['period' => $period, 'periods' => self::PERIODS, 'from' => $from];

        if ($user->can('money')) {
            $data += $stats->money($from, max(7, (int) $from->diffInDays(now()->startOfDay()) + 1));
        }

        if ($user->can('developer_view')) {
            $data += [
                'deliveries' => $stats->deliveries($from),
                'activeKeys' => ApiKey::where('tenant_id', $request->attributes->get('tenant')->id)->whereNull('revoked_at')->count(),
                'endpoints' => WebhookEndpoint::count(),
                'activeEndpoints' => WebhookEndpoint::where('active', true)->count(),
                'failedDeliveries' => WebhookDelivery::where('status', 'failed')->latest('id')->limit(5)->get(),
                'numbers' => VoiceNumber::where('tenant_id', $request->attributes->get('tenant')->id)->where('active', true)->count(),
            ];
        }

        return view('portal.dashboard', $data);
    }
}
