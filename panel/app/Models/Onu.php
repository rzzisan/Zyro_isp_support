<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An ONU read from our own OLT (engine/olt_sync.py). Read-only in the panel. */
class Onu extends Model
{
    public $timestamps = false;

    /** Below this the light is too weak (customers see drops/slow speed). */
    public const WEAK_DBM = -27;

    protected function casts(): array
    {
        return ['online' => 'boolean', 'rx_dbm' => 'float', 'tx_dbm' => 'float', 'temp_c' => 'float', 'voltage' => 'float',
            'last_change_at' => 'datetime', 'seen_at' => 'datetime'];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
