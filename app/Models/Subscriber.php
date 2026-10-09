<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(fn (Subscriber $s) => $s->phone_normalized = \App\Support\PhoneNumber::normalize($s->phone) ?: null);
    }

    public function codes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentCode::class);
    }
}
