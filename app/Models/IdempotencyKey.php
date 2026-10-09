<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class IdempotencyKey extends Model
{
    use BelongsToTenant, Prunable;

    protected $guarded = [];

    // A replayed issue response holds the plain code, so it is encrypted at rest.
    protected $casts = ['response_body' => 'encrypted:array'];

    /** Keys older than a day can no longer be replayed. */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDay());
    }
}
