<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An approved receipt of a customer (Received Bill History tab). Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerPayment extends Model
{
    protected $table = 'billing_customer_payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'entered_on' => 'date', 'approved_at' => 'datetime', 'payable' => 'decimal:2', 'paid' => 'decimal:2', 'discount' => 'decimal:2', 'balance_due' => 'decimal:2', 'is_online' => 'boolean', 'is_canceled' => 'boolean', 'synced_at' => 'datetime', 'removed_at' => 'datetime'];
    }
}
