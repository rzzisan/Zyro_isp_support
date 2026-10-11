<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bill collected from the Desk into the billing software (written by the engine). */
class BillCollection extends Model
{
    public const UPDATED_AT = null;

    public const RESULTS = ['saved' => 'নেওয়া হয়েছে', 'blocked' => 'আটকানো হয়েছে', 'failed' => 'ব্যর্থ'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'payable' => 'decimal:2', 'paid' => 'decimal:2', 'discount' => 'decimal:2',
            'send_sms' => 'boolean', 'next_billing_date' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
