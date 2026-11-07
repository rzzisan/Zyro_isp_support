<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 4a (plan 2.2 #11): a date until which an overdue customer's line stays on, with the reason. */
class DeskExtension extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const STATUSES = [
        'active' => ['চালু আছে', 'warning'],
        'ended' => ['তারিখ পার হয়েছে', 'gray'],
        'paid' => ['বিল দিয়েছেন', 'success'],
        'canceled' => ['বাতিল', 'danger'],
        'replaced' => ['নতুন তারিখ দেওয়া হয়েছে', 'gray'],
    ];

    public const KINDS = ['manual' => 'হাতে', 'bulk' => 'একসাথে অনেকজন', 'bot' => 'বট', 'import' => 'বিলিং থেকে'];

    public const LINE = [
        'enabled' => 'লাইন বন্ধ ছিল, চালু করা হয়েছে',
        'enable_failed' => 'লাইন বন্ধ ছিল, চালু হয়নি',
        'left_off' => 'লাইন বন্ধ ছিল, চালু করা হয়নি',
    ];

    protected function casts(): array
    {
        return ['until' => 'date', 'given_on' => 'date', 'over_limit' => 'boolean', 'due_at_start' => 'decimal:2',
            'created_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
