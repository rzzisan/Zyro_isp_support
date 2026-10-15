<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One send from the Desk (one number or thousands); its SMS are sms_messages, sent in the background by the engine. */
class SmsCampaign extends Model
{
    public const STATUSES = ['queued' => 'অপেক্ষায়', 'sending' => 'যাচ্ছে', 'done' => 'শেষ', 'cancelled' => 'বাতিল'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'cost' => 'decimal:2', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
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
        return $this->hasMany(SmsMessage::class, 'campaign_id');
    }

    public function open(): bool
    {
        return in_array($this->status, ['queued', 'sending'], true);
    }
}
