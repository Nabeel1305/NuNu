<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {name} {email}';

    protected $description = 'Create a platform admin for the dashboard.';

    public function handle(): int
    {
        if (Admin::where('email', $this->argument('email'))->exists()) {
            $this->error('An admin with that email already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 12 characters)');

        if (strlen((string) $password) < 12) {
            $this->error('The password is too short.');

            return self::FAILURE;
        }

        Admin::create(['name' => $this->argument('name'), 'email' => $this->argument('email'), 'password' => $password]);

        $this->info('Admin created.');

        return self::SUCCESS;
    }
}
