<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A remark on a customer in billing (Remarks History tab). Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerRemark extends Model
{
    protected $table = 'billing_customer_remarks';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
