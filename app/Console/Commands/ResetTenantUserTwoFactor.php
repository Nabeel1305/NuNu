<?php

namespace App\Console\Commands;

use App\Models\TenantUser;
use Illuminate\Console\Command;

class ResetTenantUserTwoFactor extends Command
{
    protected $signature = 'tenant-user:reset-2fa {email}';
    protected $description = 'Clear a portal user\'s authenticator so they set it up again at next sign-in.';

    public function handle(): int
    {
        $user = TenantUser::withoutGlobalScopes()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No portal user with that email.');
            return self::FAILURE;
        }

        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $this->info('Two-factor cleared.');

        return self::SUCCESS;
    }
}
