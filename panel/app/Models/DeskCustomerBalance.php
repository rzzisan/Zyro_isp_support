<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's current due in the Desk ledger next to billing's own figure (phase 1: diff, reason, since when). */
class DeskCustomerBalance extends Model
{
    protected $primaryKey = 'customer_ref';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** Why a due doesn't match billing (engine/desk_billing.py step "mismatch"), label => colour. */
    public const REASONS = [
        'recent_receipt' => ['বিলিং-এর নতুন রিসিট রাতের কপিতে আসেনি', 'gray'],
        'advance' => ['বিলিং-এ অগ্রিম আছে', 'info'],
        'not_active' => ['Active না (Inactive/Free/Personal)', 'warning'],
        'left' => ['Left', 'warning'],
        'other' => ['খুঁজে দেখতে হবে', 'danger'],
    ];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'billing_due' => 'decimal:2', 'diff' => 'decimal:2', 'recent_receipts' => 'decimal:2',
            'has_history' => 'boolean', 'excluded' => 'boolean', 'mismatch_since' => 'date', 'last_payment_on' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }
}
