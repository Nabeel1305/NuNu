<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'totp_secret' => 'encrypted',
        'totp_confirmed_at' => 'datetime',
    ];

    public function hasTwoFactor(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }
}
