<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private walkie-talkie call between two members (full duplex, its own LiveKit room). */
class WalkieCall extends Model
{
    /** A call nobody answered stops ringing after this long. */
    public const RING_SECONDS = 45;

    protected $fillable = ['company_id', 'caller_id', 'callee_id', 'status', 'answered_at', 'ended_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function room(): string
    {
        return "c{$this->company_id}-p{$this->id}";
    }

    /** Ringing too long: missed (checked whenever the call is looked at). */
    public function expire(): self
    {
        if ($this->status === 'ringing' && $this->created_at->lt(now()->subSeconds(self::RING_SECONDS))) {
            $this->update(['status' => 'missed', 'ended_at' => now()]);
        }

        return $this;
    }

    public function open(): bool
    {
        return in_array($this->status, ['ringing', 'talking'], true);
    }
}
