<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The short id is what a phone puts in an offline code. Assigned once, never changed.
        static::creating(function (Merchant $m) {
            if (empty($m->short_id)) {
                do {
                    $short = (string) random_int(10000000, 99999999);
                } while (static::withoutGlobalScopes()->where('tenant_id', $m->tenant_id ?? app(\App\Support\TenantContext::class)->id())->where('short_id', $short)->exists());
                $m->short_id = $short;
            }
        });
    }

    public function codes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentCode::class);
    }
}
