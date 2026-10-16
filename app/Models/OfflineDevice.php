<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone enrolled to sign offline payment codes for one subscriber. */
class OfflineDevice extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'key_version' => 'integer', 'last_counter' => 'integer'];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** A fresh 8-digit id, unique within the tenant. */
    public static function newShortId(int $tenantId): string
    {
        do {
            $short = (string) random_int(10000000, 99999999);
        } while (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('short_id', $short)->exists());

        return $short;
    }
}
