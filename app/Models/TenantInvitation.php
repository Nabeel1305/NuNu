<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInvitation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    /**
     * Replace any open invitation for the user with a fresh one.
     *
     * @return string the plain link token, shown once
     */
    public static function issue(TenantUser $user, int $validDays = 7): string
    {
        static::withoutGlobalScopes()->where('tenant_user_id', $user->id)->whereNull('accepted_at')->delete();

        $plain = bin2hex(random_bytes(24));
        static::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'tenant_user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays($validDays),
        ]);

        return $plain;
    }

    /** Find an open, unexpired invitation by its plain token. Sign-in style lookup: no tenant yet. */
    public static function findOpen(string $plain): ?self
    {
        return static::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id')->withoutGlobalScopes();
    }
}
