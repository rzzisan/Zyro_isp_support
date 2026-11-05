<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 1: the Desk's own monthly bill (shadow only, never in the ledger) next to billing's bill for the same month. */
class DeskShadowBill extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const RESULTS = [
        'match' => ['মিলেছে', 'success'],
        'amount_diff' => ['টাকা আলাদা', 'danger'],
        'desk_only' => ['শুধু Desk বিল করেছে', 'danger'],
        'billing_only' => ['শুধু বিলিং বিল করেছে', 'danger'],
        'pending' => ['বিলিং-এর বিল এখনো কপি হয়নি', 'gray'],
    ];

    public const REASONS = [
        'billed' => 'মাসিক বিল',
        'not_active' => 'Active না, বিল ০',
        'left' => 'Left, বিল ০',
        'joined_this_month' => 'এই মাসে নতুন, প্রথম বিল পরের মাসে',
        'zero_bill' => 'মাসিক বিল ০',
        'not_in_desk' => 'বিল বানানোর সময় Desk-এ ছিল না',
    ];

    public const HINTS = [
        'package_changed' => 'পরে প্যাকেজ বদলেছে',
        'price_changed' => 'পরে মাসিক বিল বদলেছে',
        'status_changed' => 'পরে Status বদলেছে',
        'edited_in_billing' => 'বিলিং-এ বিল হাতে সংশোধন',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'desk_amount' => 'decimal:2', 'billing_amount' => 'decimal:2', 'monthly_bill' => 'decimal:2',
            'compared_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }
}
