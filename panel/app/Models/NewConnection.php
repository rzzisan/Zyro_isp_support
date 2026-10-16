<?php

namespace App\Models;

use App\Support\Onu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A new line / fiber conversion sold: price, deposit, what is still due and who holds the money. */
class NewConnection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['connected_on' => 'date', 'materials' => 'json:unicode', 'total' => 'decimal:2', 'deposit' => 'decimal:2', 'due' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (NewConnection $c) {
            if ($c->customer_kind === Onu::OTHER) {
                $c->customer_code = null;
                $c->billing_customer_id = null;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function orderTaker(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'order_taker_id');
    }

    public function moneyWith(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'money_with_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ConnectionPayment::class, 'connection_id')->orderBy('paid_on');
    }

    /** The latest ONU given with this connection. */
    public function latestOnu(): HasOne
    {
        return $this->hasOne(OnuAssignment::class, 'connection_id')->latestOfMany('assigned_at');
    }

    /** Takes a due payment the way the old app did: deposit grows by the paid amount, due drops by paid + discount. */
    public function collect(float $paid, float $discount, ?int $collectedBy, ?int $userId, ?string $paidOn = null): ConnectionPayment
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($paid, $discount, $collectedBy, $userId, $paidOn) {
            $p = $this->payments()->create(['company_id' => $this->company_id, 'paid_on' => $paidOn ?? now('Asia/Dhaka')->toDateString(),
                'paid' => $paid, 'discount' => $discount, 'collected_by_id' => $collectedBy, 'user_id' => $userId]);
            $this->update(['deposit' => round((float) $this->deposit + $paid, 2), 'due' => round((float) $this->due - $paid - $discount, 2)]);

            return $p;
        });
    }
}
