<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One call record copied from the hotline PBX portal (App\Services\PbxPortalClient, `php artisan pbx:cdr-pull`). */
class PbxCdr extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw' => 'array', 'started_at' => 'datetime', 'answered_at' => 'datetime', 'ended_at' => 'datetime',
            'duration' => 'integer', 'billsec' => 'integer'];
    }
}
