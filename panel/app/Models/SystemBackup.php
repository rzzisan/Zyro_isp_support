<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One whole-Desk backup run, restore test or download preparation. Queued by the panel, run by engine/system_backup.py. */
class SystemBackup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['parts' => 'array', 'pruned' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'expires_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** A backup that can be restored or downloaded: finished well and still on Drive. */
    public function restorable(): bool
    {
        return in_array($this->kind, ['db', 'full'], true) && in_array($this->status, ['ok', 'partial'], true)
            && ! $this->pruned && filled($this->remote_dir);
    }

    public static function busy(): bool
    {
        return static::whereIn('status', ['queued', 'running'])->exists();
    }
}
