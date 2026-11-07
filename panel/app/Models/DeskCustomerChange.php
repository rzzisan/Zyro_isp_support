<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 5: one change to an existing customer the Desk made in billing and on the router (engine/desk_change.py); never the password. */
class DeskCustomerChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const KINDS = [
        'package' => 'প্যাকেজ বদল',
        'left' => 'Left করা',
        'back' => 'Left থেকে ফেরত',
        'password' => 'পাসওয়ার্ড বদল',
        'remove' => 'Left-এর secret মোছা',
    ];

    public const STATUSES = [
        'sending' => ['চলছে', 'gray'],
        'done' => ['হয়েছে, রাউটার ঠিক', 'success'],
        'check' => ['বিলিং-এ হয়েছে, রাউটার দেখুন', 'warning'],
        'failed' => ['হয়নি', 'danger'],
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'finished_at' => 'datetime', 'detail' => 'array', 'before' => 'array',
            'secret_disabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
