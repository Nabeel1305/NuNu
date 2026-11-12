<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RequeueWebhooks extends Command
{
    protected $signature = 'webhooks:requeue {--minutes=2 : how long a delivery may sit untouched first}';

    protected $description = 'Queue again any webhook delivery that was never attempted (the queue was down or the job was lost).';

    public function handle(): int
    {
        $n = 0;

        WebhookDelivery::withoutGlobalScopes()
            ->where('status', 'pending')->where('attempts', 0)
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(200)->get()
            ->each(function (WebhookDelivery $d) use (&$n) {
                try {
                    DeliverWebhook::dispatch($d->id, $d->tenant_id);
                    $n++;
                } catch (\Throwable $e) {
                    Log::error('Could not re-queue webhook', ['delivery' => $d->id, 'error' => $e->getMessage()]);
                }
            });

        $this->info("Queued again: {$n}.");

        return self::SUCCESS;
    }
}
