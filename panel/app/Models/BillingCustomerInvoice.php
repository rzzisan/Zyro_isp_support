<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A product or service sales invoice of a customer. Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerInvoice extends Model
{
    protected $table = 'billing_customer_invoices';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'paid' => 'decimal:2', 'discount' => 'decimal:2', 'due' => 'decimal:2', 'due_date' => 'date', 'synced_at' => 'datetime'];
    }
}
