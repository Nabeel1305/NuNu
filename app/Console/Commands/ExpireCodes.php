<?php

namespace App\Console\Commands;

use App\Services\Codes\CodeService;
use Illuminate\Console\Command;

class ExpireCodes extends Command
{
    protected $signature = 'codes:expire';

    protected $description = 'Expire issued codes past their deadline and release their holds.';

    public function handle(CodeService $codes): int
    {
        $this->info($codes->expireDue() . ' code(s) expired.');

        return self::SUCCESS;
    }
}
