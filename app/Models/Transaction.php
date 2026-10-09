<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['amount_minor' => 'integer', 'settled_at' => 'datetime'];

    public function paymentCode(): BelongsTo
    {
        return $this->belongsTo(PaymentCode::class);
    }
}
