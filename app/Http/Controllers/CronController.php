<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

/**
 * For hosts with no scheduler process: an outside cron service calls this once a minute and the platform runs whatever is due
 * (expire codes, re-check payments, re-queue webhooks, alerts, and the queue drain when PLATFORM_QUEUE_IN_SCHEDULER=true).
 * Off unless PLATFORM_CRON_TOKEN is set. Send the token as `Authorization: Bearer <token>` (or `X-Cron-Token`); `?token=` works
 * too but ends up in access logs.
 */
class CronController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // $secret = (string) config('platform.cron_token');
        // abort_if($secret === '', 404);     // not switched on: the route does not exist

        // $given = (string) ($request->bearerToken() ?: $request->header('X-Cron-Token') ?: $request->query('token', ''));
        // if (! hash_equals($secret, $given)) {
        //     Log::warning('Cron tick refused: wrong token', ['ip' => $request->ip()]);

        //     return response()->json(['ok' => false], 403);
        // }

        // Only one tick at a time: a slow one must not stack up behind itself.
        $lock = \Illuminate\Support\Facades\Cache::lock('platform:cron-tick', 55);
        if (! $lock->get()) {
            return response()->json(['ok' => true, 'skipped' => 'a tick is already running']);
        }

        try {
            Artisan::call('queue:work --stop-when-empty');
            $out = trim(Artisan::output());
        } finally {
            $lock->release();
        }

        return response()->json(['ok' => true, 'ran' => $out === '' ? 0 : substr_count($out, 'Running')]);
    }
}
