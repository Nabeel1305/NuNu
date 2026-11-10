<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertService;
use Illuminate\Console\Command;

class CheckAlerts extends Command
{
    protected $signature = 'platform:check-alerts';

    protected $description = 'Tell operators (Slack / email) about stuck payments, failing webhooks and failed jobs.';

    public function handle(AlertService $alerts): int
    {
        $raised = $alerts->check();
        $this->info($raised === [] ? 'Nothing new to report.' : 'Reported: ' . implode(', ', $raised));

        return self::SUCCESS;
    }
}
