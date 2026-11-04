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

    public const SOURCES = ['webhook' => 'Webhook', 'paybill' => 'PayBill', 'desk_webhook' => 'bKash Webhook (Desk)'];

    /** Desk webhook payments put into billing (engine/bkash_webhook.settle): settle_state => [label, color] */
    public const SETTLE = [
        'pending' => ['জমা হবে', 'warning'],
        'working' => ['জমা হচ্ছে', 'warning'],
        'settled' => ['বিলিং-এ জমা হয়েছে', 'success'],
        'failed' => ['জমা হয়নি', 'danger'],
        'off' => ['নিজে জমা বন্ধ', 'gray'],
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'billing_created_at' => 'datetime', 'billing_updated_at' => 'datetime',
            'alerted_at' => 'datetime', 'synced_at' => 'datetime', 'settled_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statusLabel(): string
    {
        if ($this->source === 'desk_webhook') {
            return $this->customer_header_id ? __(self::SETTLE[$this->settle_state][0] ?? 'Desk-এ এসেছে') : __('কাস্টমার চেনা যায়নি');
        }

        return __(self::STATUSES[$this->status_id][0] ?? ($this->status ?: '—'));
    }

    public function statusColor(): string
    {
        if ($this->source === 'desk_webhook') {
            return $this->customer_header_id ? (self::SETTLE[$this->settle_state][1] ?? 'info') : 'danger';
        }

        return self::STATUSES[$this->status_id][1] ?? 'gray';
    }

    /** A Desk webhook payment staff can still put into billing (a customer picked, not settled yet). */
    public function canSettle(): bool
    {
        return $this->source === 'desk_webhook' && ! in_array($this->settle_state, ['settled', 'working'], true);
    }

    /** billing_customers row id of the matched customer, for the customer page link. */
    public function customerRowId(): ?int
    {
        return $this->customer_header_id ? BillingCustomer::where('company_id', $this->company_id)
            ->where('header_id', $this->customer_header_id)->value('id') : null;
    }
}
