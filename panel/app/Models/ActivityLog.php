<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One write action of a user, bot or timer (plan 2.4ক). Append-only: PostgreSQL refuses UPDATE/DELETE and links each
 * row to the one before it (seq, prev_hash, hash), so rows are only ever created through App\Support\Activity.
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('activity_logs is append-only'));
        static::deleting(fn () => throw new LogicException('activity_logs is append-only'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
