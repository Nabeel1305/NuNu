<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
