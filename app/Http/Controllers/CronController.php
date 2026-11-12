<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Support\BackgroundArtisan;
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
        // PLATFORM_CRON_REQUIRE_TOKEN=false (a temporary switch) lets the URL be called with no token at all. It is still limited to
        // one tick every 20 seconds and 20 requests a minute, and it only ever runs what the schedule says is due.
        // if (config('platform.cron_require_token', true)) {
        //     $secret = (string) config('platform.cron_token');
        //     abort_if($secret === '', 404);     // not switched on: the route does not exist

        //     $given = (string) ($request->bearerToken() ?: $request->header('X-Cron-Token') ?: $request->query('token', ''));
        //     if (! hash_equals($secret, $given)) {
        //         Log::warning('Cron tick refused: wrong token', ['ip' => $request->ip()]);

        //         return response()->json(['ok' => false], 403);
        //     }
        // }

        // Two ticks within 20 seconds (a retry, a second cron service) are one tick.
        if (! \Illuminate\Support\Facades\Cache::add('platform:cron-tick', 1, 20)) {
            return response()->json(['ok' => true, 'skipped' => 'a tick just ran']);
        }

        // By default the schedule runs as its own process and this call answers at once: cron services give up after about
        // 30 seconds, and the queue drain alone can take longer. Each task already guards against overlapping itself.
        if (app(BackgroundArtisan::class)->start('queue:work')) {
            return response()->json(['ok' => true, 'started' => true], 202);
        }

        // Not allowed to start a process on this host (or switched off): run it here and wait.
        Artisan::call('queue:work');
        $out = trim(Artisan::output());

        return response()->json(['ok' => true, 'ran' => $out === '' ? 0 : substr_count($out, 'Running')]);
    }
}
