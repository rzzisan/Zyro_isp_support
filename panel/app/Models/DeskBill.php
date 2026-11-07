<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A monthly bill in the Desk's own ledger. */
class DeskBill extends Model
{
    protected $guarded = [];

    public const STATUSES = [
        'open' => ['বাকি', 'danger'], 'partial' => ['আংশিক', 'warning'], 'paid' => ['শোধ', 'success'],
        'waived' => ['মাফ', 'gray'], 'void' => ['বাতিল', 'gray'],
    ];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }
}
