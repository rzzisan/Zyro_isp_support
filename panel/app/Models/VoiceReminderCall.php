<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One voice reminder call: queued by the panel (test) or the scheduler, placed and run by engine/voice_ivr.py. */
class VoiceReminderCall extends Model
{
    public const STATUSES = ['planned' => 'সময়ের অপেক্ষায়', 'queued' => 'অপেক্ষায়', 'dialing' => 'রিং হচ্ছে', 'answered' => 'কথা চলছে', 'done' => 'শেষ',
        'no_answer' => 'ধরেনি', 'busy' => 'ব্যস্ত', 'failed' => 'যায়নি', 'skipped' => 'বাদ', 'expired' => 'সময় পেরিয়েছে'];

    public const KINDS = ['test' => 'টেস্ট', 'reminder' => 'বিল রিমাইন্ডার', 'line_off' => 'লাইন বন্ধ', 'inbound' => 'নিজে কল (লাইন বন্ধ)'];

    public const RESULTS = ['listened' => 'শুনেছেন', 'sms' => 'SMS নিয়েছেন', 'reconnected' => 'লাইন চালু', 'reconnect_failed' => 'লাইন চালু হয়নি',
        'staff' => 'স্টাফের সাথে কথা', 'voicemail' => 'ভয়েস মেসেজ', 'callback' => 'কল-ব্যাক চান', 'optout' => 'আর কল নয়',
        'ticket' => 'টিকেট', 'hung_up' => 'কেটে দিয়েছেন'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['package' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'answered_at' => 'datetime',
            'ended_at' => 'datetime', 'ref_date' => 'date', 'sms_backup' => 'boolean', 'reached' => 'boolean', 'settled_at' => 'datetime', 'backup_sms_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(VoiceFlow::class, 'voice_flow_id');
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }
}
