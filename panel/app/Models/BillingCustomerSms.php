<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An SMS billing sent a customer, last 12 months (Message History tab). Copied by engine/customer_history.py; read-only in the panel. */
class BillingCustomerSms extends Model
{
    protected $table = 'billing_customer_sms';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
