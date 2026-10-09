<?php

namespace App\Console\Commands;

use App\Services\Codes\CodeService;
use Illuminate\Console\Command;

class ReconcileTransactions extends Command
{
    protected $signature = 'transactions:reconcile';

    protected $description = 'Resolve pending transactions whose capture never reported back.';

    public function handle(CodeService $codes): int
    {
        $c = $codes->reconcilePending();

        $this->info("Settled {$c['settled']}, failed {$c['failed']}, retried {$c['retried']}, flagged {$c['flagged']}.");

        return self::SUCCESS;
    }
}
