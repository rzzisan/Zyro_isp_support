<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One WhatsApp template send from the Desk; its messages are wa_campaign_messages, sent by engine/wa_campaign.py. */
class WaCampaign extends Model
{
    public const STATUSES = ['queued' => 'অপেক্ষায়', 'sending' => 'যাচ্ছে', 'paused' => 'থেমে আছে', 'done' => 'শেষ',
        'cancelled' => 'বাতিল'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array', 'filters' => 'array', 'cost' => 'decimal:2', 'started_at' => 'datetime',
            'finished_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaCampaignMessage::class, 'campaign_id');
    }

    public function open(): bool
    {
        return in_array($this->status, ['queued', 'sending', 'paused'], true);
    }
}
