<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What operators did on the console and when they signed in. Append-only: nothing in the app updates or deletes a row. */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}
