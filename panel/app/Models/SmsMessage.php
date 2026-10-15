<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One SMS to one number (the Desk SMS log). Status is kept up to date by engine/sms.py. */
class SmsMessage extends Model
{
    public const STATUSES = [
        'queued' => 'অপেক্ষায়', 'sending' => 'যাচ্ছে', 'sent' => 'গেটওয়েতে গেছে', 'delivered' => 'পৌঁছেছে',
        'failed' => 'ব্যর্থ', 'cancelled' => 'বাতিল', 'unknown' => 'অজানা',
    ];

    public const COLORS = [
        'queued' => 'gray', 'sending' => 'info', 'sent' => 'info', 'delivered' => 'success',
        'failed' => 'danger', 'cancelled' => 'gray', 'unknown' => 'warning',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unicode' => 'boolean', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'status_checked_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
