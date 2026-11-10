<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Audit\AuditLogService;
use App\Support\PlatformAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/** Is the platform itself healthy: database, queue, scheduler, secrets, alerts. */
class HealthController extends Controller
{
    /** @return list<array{label:string,status:string,detail:string}> status: ok | warn | fail */
    public static function checks(): array
    {
        $out = [];
        $add = function (string $label, string $status, string $detail) use (&$out) { $out[] = compact('label', 'status', 'detail'); };

        try {
            DB::select('select 1');
            $add('Database', 'ok', 'Reachable.');
        } catch (\Throwable $e) {
            $add('Database', 'fail', 'Cannot query the database.');
        }

        try {
            Cache::put('platform:health', 1, 10);
            $add('Cache', Cache::get('platform:health') === 1 ? 'ok' : 'warn', Cache::get('platform:health') === 1 ? 'Read and write work.' : 'Writes did not read back.');
        } catch (\Throwable $e) {
            $add('Cache', 'fail', 'Cache is not working.');
        }

        $driver = config('queue.default');
        $add('Queue driver', in_array($driver, ['sync'], true) ? 'fail' : ($driver === 'database' ? 'warn' : 'ok'),
            $driver === 'sync' ? 'Queue is "sync": webhooks run inside requests. Use redis.' : ($driver === 'database' ? 'Database queue deadlocks between workers. Use redis in production.' : "Using {$driver}."));

        if (Schema::hasTable('jobs') && $driver === 'database') {
            $oldest = DB::table('jobs')->min('created_at');
            $age = $oldest ? now()->timestamp - $oldest : 0;
            $add('Queue backlog', $age > 300 ? 'fail' : ($age > 60 ? 'warn' : 'ok'), DB::table('jobs')->count() . ' waiting' . ($oldest ? ", oldest {$age}s old" : '') . ($age > 300 ? '. Is a worker running?' : '.'));
        }

        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $add('Failed jobs', $failed ? 'warn' : 'ok', $failed ? "{$failed} failed job(s). Run php artisan queue:retry all after fixing the cause." : 'None.');

        $beat = Cache::get('platform:scheduler:last');
        $add('Scheduler', $beat && now()->timestamp - $beat < 180 ? 'ok' : 'fail',
            $beat ? 'Last ran ' . now()->diffInSeconds(\Illuminate\Support\Carbon::createFromTimestamp($beat)) . 's ago.' : 'No sign of it running. Codes will not expire and payments will not be re-checked. Run php artisan schedule:work.');

        $add('Device key secret', filled(config('platform.device_key_secret')) ? 'ok' : 'warn', filled(config('platform.device_key_secret')) ? 'Set.' : 'PLATFORM_DEVICE_KEY_SECRET is empty: offline phone codes cannot be used.');
        $add('Code pepper', filled(config('platform.code_pepper') ?? env('PLATFORM_CODE_PEPPER')) ? 'ok' : 'fail', filled(config('platform.code_pepper') ?? env('PLATFORM_CODE_PEPPER')) ? 'Set. Never change it.' : 'PLATFORM_CODE_PEPPER is empty.');
        $add('Debug mode', config('app.debug') && app()->environment('production') ? 'fail' : 'ok', config('app.debug') && app()->environment('production') ? 'APP_DEBUG is on in production: errors leak details.' : 'Off, or not production.');
        $add('Operator two-factor', \App\Http\Middleware\EnsureTwoFactor::required() ? 'ok' : 'warn', \App\Http\Middleware\EnsureTwoFactor::required() ? 'Required for every operator.' : 'Not required on this deployment.');

        $alerts = filled(config('platform.alerts.slack_webhook')) || filled(config('platform.alerts.emails'));
        $add('Alerts', $alerts ? 'ok' : 'warn', $alerts ? 'Sent to ' . implode(' and ', array_filter([filled(config('platform.alerts.slack_webhook')) ? 'Slack' : null, filled(config('platform.alerts.emails')) ? 'email' : null])) . '.' : 'No Slack webhook or email set (PLATFORM_ALERT_SLACK_WEBHOOK / PLATFORM_ALERT_EMAILS): nobody is told when something breaks.');
        $mail = config('mail.default');
        $add('Mail', in_array($mail, ['log', 'array'], true) ? 'warn' : 'ok', in_array($mail, ['log', 'array'], true) ? 'Mailer is "' . $mail . '": emails are not really sent.' : "Using {$mail}.");

        $audit = Cache::get('platform:audit:last');
        $add('Audit chains', ! $audit ? 'warn' : ($audit['broken'] ? 'fail' : 'ok'), ! $audit ? 'Not checked yet. Use "Check now".' : ($audit['broken'] ? 'BROKEN for: ' . implode(', ', $audit['broken']) : 'All intact') . ' (checked ' . \Illuminate\Support\Carbon::createFromTimestamp($audit['at'])->diffForHumans() . ').');

        return $out;
    }

    public function index(): View
    {
        $checks = self::checks();

        return view('admin.health.index', ['checks' => $checks, 'bad' => collect($checks)->where('status', 'fail')->count(), 'warn' => collect($checks)->where('status', 'warn')->count()]);
    }

    public function verifyAudit(Request $request, AuditLogService $audit): RedirectResponse
    {
        $broken = [];
        foreach (Tenant::all() as $t) {
            if ($audit->verifyChain($t->id) !== []) {
                $broken[] = $t->slug;
            }
        }
        Cache::forever('platform:audit:last', ['at' => now()->timestamp, 'broken' => $broken]);
        PlatformAudit::record($request->user('admin'), 'admin.audit_verified', $broken ? 'Audit check found broken chains' : 'Audit check: all intact', null, null, ['broken' => $broken]);

        return back()->with('status', $broken ? 'Broken chain for: ' . implode(', ', $broken) : 'Every tenant\'s audit chain is intact.');
    }
}
