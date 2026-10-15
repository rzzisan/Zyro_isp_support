<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A ready SMS text the SMS form can start from; may hold placeholders like {name} {due} (App\Support\Sms::PLACEHOLDERS). */
class SmsTemplate extends Model
{
    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
