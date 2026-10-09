<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function codes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentCode::class);
    }
}
