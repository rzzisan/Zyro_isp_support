<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An online payment (bKash/Nagad webhook or PayBill) from the billing software, copied by engine/online_payment_sync.py. Read-only here. */
class BillingOnlinePayment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** billing's StatusId => [label, color] */
    public const STATUSES = [
        1 => ['সেটেল হয়নি (Payment Requested)', 'danger'],
        2 => ['অটো সেটেল', 'success'],
        3 => ['হাতে সেটেল', 'info'],
        4 => ['Payment Transferred', 'gray'],
        8 => ['Payment Retransferred', 'gray'],
    ];

    public const SOURCES = ['webhook' => 'Webhook', 'paybill' => 'PayBill'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'billing_created_at' => 'datetime', 'billing_updated_at' => 'datetime',
            'alerted_at' => 'datetime', 'synced_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status_id][0] ?? ($this->status ?: '—'));
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status_id][1] ?? 'gray';
    }

    /** billing_customers row id of the matched customer, for the customer page link. */
    public function customerRowId(): ?int
    {
        return $this->customer_header_id ? BillingCustomer::where('company_id', $this->company_id)
            ->where('header_id', $this->customer_header_id)->value('id') : null;
    }
}
