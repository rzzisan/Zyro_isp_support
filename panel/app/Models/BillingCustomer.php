<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer copied from the billing software (engine/customer_sync.py). Read-only in the panel. */
class BillingCustomer extends Model
{
    protected $guarded = [];

    protected $hidden = ['pppoe_password'];

    protected function casts(): array
    {
        return [
            'pppoe_password' => 'encrypted', 'extra' => 'array', 'disabled' => 'boolean', 'is_vip' => 'boolean',
            'monthly_bill' => 'decimal:2', 'payable' => 'decimal:2', 'paid' => 'decimal:2', 'due' => 'decimal:2',
            'advance' => 'decimal:2', 'last_payment_date' => 'date', 'joined_on' => 'date', 'registered_on' => 'date',
            'synced_at' => 'datetime', 'gone_at' => 'datetime', 'details_fetched_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chat(): ?WaContact
    {
        return $this->mobile_normalized
            ? WaContact::where('company_id', $this->company_id)
                ->where(fn ($q) => $q->where('wa_number', $this->mobile_normalized)->orWhere('customer_id', $this->customer_id))
                ->latest('last_message_at')->first()
            : WaContact::where('company_id', $this->company_id)->where('customer_id', $this->customer_id)->first();
    }
}
