<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One incoming hotline call, written by the engine (engine/sip_calls.py) while the company's extension rings.
 * "ended" only means it stopped ringing here: the PBX does not tell us whether someone else picked it up.
 */
class CallLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The company's customers with this caller's mobile (several lines can share one number). */
    public function customers(int $limit = 3): Collection
    {
        if (! $this->caller_normalized) {
            return collect();
        }

        return BillingCustomer::where('company_id', $this->company_id)->where('mobile_normalized', $this->caller_normalized)
            ->orderBy('is_left')->orderByDesc('header_id')->limit($limit)->get();
    }

    public function scopeRecent(Builder $q, int $companyId, int $minutes = 3): Builder
    {
        return $q->where('company_id', $companyId)->where('started_at', '>=', now()->subMinutes($minutes));
    }
}
