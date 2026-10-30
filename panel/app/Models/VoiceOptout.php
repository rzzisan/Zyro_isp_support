<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A number that gets no more voice reminder calls (the customer pressed 9, or staff added it). */
class VoiceOptout extends Model
{
    public const SOURCES = ['call' => 'কলে ৯ চেপেছেন', 'staff' => 'স্টাফ যোগ করেছেন'];

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
