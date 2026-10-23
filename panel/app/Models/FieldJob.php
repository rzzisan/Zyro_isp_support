<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A field technician's work on one ticket: started in the app, paused with a reason, finished when the ticket is solved. */
class FieldJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'paused_at' => 'datetime', 'finished_at' => 'datetime', 'line_checked_at' => 'datetime',
            'line_check' => 'boolean', 'line_online' => 'boolean', 'start_lat' => 'float', 'start_lng' => 'float', 'start_mock' => 'boolean',
            'finish_lat' => 'float', 'finish_lng' => 'float', 'finish_mock' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(BillingTicket::class, 'ticket_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FieldJobEvent::class);
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    /** Working seconds so far: from start until now (or the finish / the pause), less the paused time. */
    public function workedSeconds(): int
    {
        $end = $this->finished_at ?? $this->paused_at ?? now();

        return max(0, (int) $this->started_at->diffInSeconds($end) - $this->paused_seconds);
    }

    public function log(string $type, ?int $userId, ?string $note = null): void
    {
        $this->events()->create(['type' => $type, 'user_id' => $userId, 'note' => $note, 'created_at' => now()]);
    }
}
