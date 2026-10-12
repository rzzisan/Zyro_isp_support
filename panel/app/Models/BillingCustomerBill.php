<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bill billing generated for a customer (Generated & Updated Bills tab). Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerBill extends Model
{
    protected $table = 'billing_customer_bills';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_on' => 'date', 'bill_amount' => 'decimal:2', 'discount' => 'decimal:2', 'paid' => 'decimal:2', 'due' => 'decimal:2', 'is_editable' => 'boolean', 'versions' => 'array', 'synced_at' => 'datetime', 'removed_at' => 'datetime'];
    }
}
