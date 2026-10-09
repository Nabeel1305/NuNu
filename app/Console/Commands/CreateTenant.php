<?php

namespace App\Console\Commands;

use App\Services\Tenants\TenantProvisioner;
use Illuminate\Console\Command;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create {name} {--voice-mode=own : own or shared} {--environment=sandbox : sandbox or live}';

    protected $description = 'Create a tenant and print its first API key (shown once).';

    public function handle(): int
    {
        if (! in_array($this->option('voice-mode'), ['own', 'shared'], true)
            || ! in_array($this->option('environment'), ['sandbox', 'live'], true)) {
            $this->error('voice-mode must be own or shared; environment must be sandbox or live.');

            return self::FAILURE;
        }

        [$tenant, $plain] = app(TenantProvisioner::class)->create(
            $this->argument('name'), $this->option('voice-mode'), $this->option('environment')
        );

        $this->info("Tenant {$tenant->slug} created (short code {$tenant->short_code}).");
        $this->line("API key (shown once): {$plain}");

        return self::SUCCESS;
    }
}
