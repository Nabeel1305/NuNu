<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    public const EVENTS = ['code.redeemed', 'transaction.settled', 'transaction.failed', 'code.expired'];

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted', 'events' => 'array', 'active' => 'boolean'];

    public function wants(string $event): bool
    {
        return $this->active && ($this->events === null || in_array($event, $this->events, true));
    }
}
