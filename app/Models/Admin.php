<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
    public const ROLES = ['owner', 'support', 'finance'];

    /**
     * owner    everything, including operators, platform settings and live-tenant changes
     * support  look, help and fix: view as a user, retry webhooks, re-check payments, revoke devices, reset a person's 2FA
     * finance  read only: dashboard, payments, reports
     */
    private const ABILITIES = [
        'owner' => ['view', 'reports', 'view_as', 'payments', 'webhooks', 'devices', 'portal_users', 'audit', 'tenants', 'operators', 'settings'],
        'support' => ['view', 'reports', 'view_as', 'payments', 'webhooks', 'devices', 'portal_users', 'audit'],
        'finance' => ['view', 'reports'],
    ];

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'totp_secret' => 'encrypted',
        'totp_confirmed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
    ];

    public function hasTwoFactor(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    public function can($abilities, $arguments = []): bool
    {
        return in_array($abilities, self::ABILITIES[$this->role ?? 'owner'] ?? [], true);
    }

    public function isOwner(): bool
    {
        return ($this->role ?? 'owner') === 'owner';
    }
}
