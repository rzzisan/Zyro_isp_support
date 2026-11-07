<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment in the Desk's own ledger: copied from billing (source ispdigital) or taken on the Desk (source desk). */
class DeskPayment extends Model
{
    protected $guarded = [];

    public const STATUSES = [
        'approved' => ['জমা', 'success'],
        'pending' => ['approve বাকি', 'warning'],
        'canceled' => ['বাতিল', 'danger'],
        'transferred' => ['অন্য ID-তে ট্রান্সফার', 'gray'],
    ];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'paid_at' => 'datetime', 'amount' => 'decimal:2', 'discount' => 'decimal:2',
            'is_online' => 'boolean', 'canceled_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }
}
