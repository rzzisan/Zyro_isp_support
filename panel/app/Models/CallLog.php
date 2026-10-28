<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One incoming call. channel hotline: written by the engine (engine/sip_calls.py) while the company's extension rings;
 * channel whatsapp: a WhatsApp call (Meta Calling API over SIP), written by engine/voice_bot.py when it ends.
 * "ended" only means it stopped ringing here: the PBX does not tell us whether someone else picked it up.
 * When the voice bot answered (engine/voice_bot.py): bot_answered, the recording, transcript and callback reason.
 */
class CallLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'bot_answered' => 'boolean', 'transcript' => 'array', 'actions' => 'array'];
    }

    /** The staff member who talked (took a WhatsApp call, or called the customer out). */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
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

    /** The call log exists for a company with a hotline (SIP) or a WhatsApp number that takes calls. */
    public static function enabledFor(int $companyId): bool
    {
        return SipSetting::where('company_id', $companyId)->exists()
            || WaAccount::where('company_id', $companyId)->where('calling_mode', '!=', 'off')->exists();
    }

    public function scopeRecent(Builder $q, int $companyId, int $minutes = 3): Builder
    {
        return $q->where('company_id', $companyId)->where('started_at', '>=', now()->subMinutes($minutes));
    }
}
