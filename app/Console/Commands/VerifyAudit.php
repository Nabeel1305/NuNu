<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Audit\AuditLogService;
use Illuminate\Console\Command;

class VerifyAudit extends Command
{
    protected $signature = 'audit:verify {tenant? : tenant slug; omit to check every tenant}';

    protected $description = 'Check each tenant\'s audit chain for tampering. Exits 1 if any entry does not match.';

    public function handle(AuditLogService $audit): int
    {
        $tenants = $this->argument('tenant')
            ? Tenant::where('slug', $this->argument('tenant'))->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error('No matching tenant.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($tenants as $tenant) {
            $broken = $audit->verifyChain($tenant->id);

            if ($broken === []) {
                $this->info("{$tenant->slug}: intact");
            } else {
                $failed = true;
                $this->error("{$tenant->slug}: BROKEN at entries " . implode(', ', $broken));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
