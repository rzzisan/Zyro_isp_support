<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An online PPPoE session from a MikroTik (engine/ppp_sync.py, every 2 minutes). */
class PppSession extends Model
{
    public $timestamps = false;

    /** A session counts as online only if the last 2-minute copy saw it. */
    public const FRESH_MINUTES = 5;

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function isFresh(): bool
    {
        return $this->seen_at && $this->seen_at->gt(now()->subMinutes(self::FRESH_MINUTES));
    }
}
