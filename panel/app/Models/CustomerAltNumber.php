<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of a customer's other numbers (App\Support\AltNumbers): from their history or added by staff. */
class CustomerAltNumber extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime', 'tried_at' => 'datetime', 'reached_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'customer_header_id', 'header_id')
            ->where('billing_customers.company_id', $this->company_id);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('removed_at');
    }

    /** @return string[] */
    public function sourceList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->sources)));
    }
}
