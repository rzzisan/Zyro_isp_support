<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One WhatsApp template message to one number. Status comes from engine/wa_campaign.py and Meta's webhooks. */
class WaCampaignMessage extends Model
{
    public const STATUSES = [
        'queued' => 'অপেক্ষায়', 'sending' => 'যাচ্ছে', 'sent' => 'Meta নিয়েছে', 'delivered' => 'পৌঁছেছে',
        'read' => 'পড়েছেন', 'failed' => 'ব্যর্থ', 'cancelled' => 'বাতিল', 'unknown' => 'অজানা',
    ];

    public const COLORS = [
        'queued' => 'gray', 'sending' => 'info', 'sent' => 'info', 'delivered' => 'success', 'read' => 'success',
        'failed' => 'danger', 'cancelled' => 'gray', 'unknown' => 'warning',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WaCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
