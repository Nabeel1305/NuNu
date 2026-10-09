<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A person who signs in to one tenant's portal. Scoped to the tenant in TenantContext once a
 * portal request is under way; sign-in itself runs before any context exists, so lookups by
 * email see every tenant and must go on to check the account they find.
 */
class TenantUser extends Authenticatable
{
    use BelongsToTenant;

    public const ROLES = ['owner', 'developer', 'viewer'];

    /**
     * owner     everything, including the team and cancelling codes
     * developer keys, webhooks and delivery logs; no money views
     * viewer    read-only: transactions, codes, parties, audit, delivery logs
     */
    private const ABILITIES = [
        'owner' => ['money', 'developer_view', 'manage_keys', 'manage_webhooks', 'audit', 'team', 'cancel_code'],
        'developer' => ['developer_view', 'manage_keys', 'manage_webhooks'],
        'viewer' => ['money', 'developer_view', 'audit'],
    ];

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'totp_secret' => 'encrypted',
        'totp_confirmed_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function can($abilities, $arguments = []): bool
    {
        return in_array($abilities, self::ABILITIES[$this->role] ?? [], true);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function hasTwoFactor(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    public function hasAccepted(): bool
    {
        return $this->password !== null;
    }
}
