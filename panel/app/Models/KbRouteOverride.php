<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Super-admin routing change on a KB file (plan §19.3). action: add = new related link, disable = switch off a repo link. */
class KbRouteOverride extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created_at' => 'datetime'];
    }
}
