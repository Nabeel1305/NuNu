<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:reset-2fa {email}';

    protected $description = 'Remove an admin\'s authenticator so they can set it up again. Needs server access by design.';

    public function handle(): int
    {
        $admin = Admin::where('email', $this->argument('email'))->first();

        if (! $admin) {
            $this->error('No admin with that email.');

            return self::FAILURE;
        }

        $admin->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();

        // Anyone signed in as this admin loses their session, in case the reset is because of a compromise.
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $admin->id)->delete();

        $this->info("Two-factor reset for {$admin->email}. They will set it up again at their next sign-in.");

        return self::SUCCESS;
    }
}
