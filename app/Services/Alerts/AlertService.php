<?php

namespace App\Services\Alerts;

use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Tells operators when something needs a person: payments stuck, payments failing, webhooks failing, jobs failing.
 * Each problem is reported once, then again only after platform.alerts.repeat_hours while it persists.
 */
class AlertService
{
    /** @return list<string> the alerts that were raised this run */
    public function check(): array
    {
        $raised = [];
        foreach ($this->problems() as $key => $message) {
            if (Cache::add("platform:alert:{$key}", 1, now()->addHours((int) config('platform.alerts.repeat_hours')))) {
                $this->send($message);
                $raised[] = $key;
            }
        }

        return $raised;
    }

    /** @return array<string,string> key => message, for every problem there is right now */
    public function problems(): array
    {
        $p = [];

        $stuckMin = PlatformSettings::effective('alerts.stuck_minutes');
        $stuck = DB::table('transactions')->where('status', 'pending')->where('created_at', '<=', now()->subMinutes($stuckMin))->count();
        if ($stuck > 0) {
            $p['stuck'] = "{$stuck} payment(s) have been pending for over {$stuckMin} minutes.";
        }

        $failedPay = DB::table('transactions')->where('status', 'failed')->where('updated_at', '>=', now()->subHour())->count();
        if ($failedPay >= PlatformSettings::effective('alerts.failed_payments')) {
            $p['failed_payments'] = "{$failedPay} payments failed in the last hour.";
        }

        $failedHooks = DB::table('webhook_deliveries')->where('status', 'failed')->where('updated_at', '>=', now()->subHour())->count();
        if ($failedHooks >= PlatformSettings::effective('alerts.webhook_failures')) {
            $p['webhooks'] = "{$failedHooks} webhook deliveries gave up in the last hour.";
        }

        $unsent = DB::table('webhook_deliveries')->where('status', 'pending')->where('attempts', 0)->where('created_at', '<=', now()->subMinutes(5))->count();
        if ($unsent > 0) {
            $p['webhook_unsent'] = "{$unsent} webhook event(s) have waited over 5 minutes and were never attempted: the queue worker is probably not running, so banks are not being told about payments.";
        }

        if (Schema::hasTable('failed_jobs') && ($n = DB::table('failed_jobs')->count()) > 0) {
            $p['jobs'] = "{$n} queue job(s) have failed.";
        }

        $audit = Cache::get('platform:audit:last');
        if ($audit && $audit['broken']) {
            $p['audit'] = 'An audit chain is BROKEN for: ' . implode(', ', $audit['broken']) . '. Possible tampering.';
        }

        return $p;
    }

    public function send(string $message): void
    {
        $text = '[Offline Platform ' . config('app.name') . '] ' . $message;

        if ($url = config('platform.alerts.slack_webhook')) {
            try {
                Http::timeout(8)->post($url, ['text' => $text]);
            } catch (\Throwable $e) {
                Log::error('Slack alert failed', ['error' => $e->getMessage()]);
            }
        }

        foreach (array_filter(array_map('trim', explode(',', (string) config('platform.alerts.emails')))) as $to) {
            try {
                Mail::raw($text, fn ($m) => $m->to($to)->subject('Offline Platform alert'));
            } catch (\Throwable $e) {
                Log::error('Email alert failed', ['error' => $e->getMessage()]);
            }
        }

        Log::warning($text);
    }
}
