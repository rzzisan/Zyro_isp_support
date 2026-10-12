<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change of a customer in billing (Customer Change Log tab). Never holds a password. Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerChangeLog extends Model
{
    protected $table = 'billing_customer_change_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime', 'snapshot' => 'array', 'changed' => 'array', 'password_changed' => 'boolean'];
    }
}
