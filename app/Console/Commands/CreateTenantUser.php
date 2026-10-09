<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use Illuminate\Console\Command;

class CreateTenantUser extends Command
{
    protected $signature = 'tenant-user:create {tenant : tenant slug} {name} {email} {--role=owner : owner, developer or viewer}';
    protected $description = 'Invite a person to a tenant\'s portal. Prints a one-time link; the person chooses their own password.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('No tenant with that slug.');
            return self::FAILURE;
        }
        if (! in_array($this->option('role'), TenantUser::ROLES, true)) {
            $this->error('Role must be one of: ' . implode(', ', TenantUser::ROLES));
            return self::FAILURE;
        }
        if (TenantUser::withoutGlobalScopes()->where('email', $this->argument('email'))->exists()) {
            $this->error('A portal user with that email already exists.');
            return self::FAILURE;
        }

        $user = TenantUser::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $this->argument('name'), 'email' => $this->argument('email'),
            'role' => $this->option('role'), 'is_active' => true,
        ]);

        $this->info('Invitation link (valid 7 days, works once):');
        $this->line(route('portal.invitation', TenantInvitation::issue($user)));

        return self::SUCCESS;
    }
}
