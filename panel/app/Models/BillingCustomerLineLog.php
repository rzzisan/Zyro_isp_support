<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A MikroTik enable / disable of a customer in billing. Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerLineLog extends Model
{
    protected $table = 'billing_customer_line_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
