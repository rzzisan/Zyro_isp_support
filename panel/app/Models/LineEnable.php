<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line a technician, the Desk or the customer (engine/self_reconnect.py) asked to turn on/off (written by the engine). */
class LineEnable extends Model
{
    public const UPDATED_AT = null;

    public const RESULTS = ['enabled' => 'চালু করা হয়েছে', 'disabled' => 'বন্ধ করা হয়েছে', 'already_active' => 'আগেই চালু ছিল',
        'already_disabled' => 'আগেই বন্ধ ছিল', 'not_allowed' => 'অনুমতি নেই', 'failed' => 'ব্যর্থ', 'dry_run' => 'টেস্ট মোড',
        'used_this_month' => 'এই মাসে আগেই নিয়েছেন', 'due_too_high' => 'এক মাসের বেশি বকেয়া', 'not_bill' => 'বিলের কারণে বন্ধ না', 'off' => 'সুবিধা বন্ধ',
        'number_limit' => 'নম্বরের মাসিক সীমা শেষ'];

    public const ACTIONS = ['enable' => 'চালু', 'disable' => 'বন্ধ'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
