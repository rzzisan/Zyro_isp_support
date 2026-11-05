<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 2: whose line the Desk would turn off that day (shadow only, nothing is turned off) next to what billing did. */
class DeskDisableShadow extends Model
{
    protected $table = 'desk_disable_shadow';

    public $timestamps = false;

    protected $guarded = [];

    public const DECISIONS = [
        'disable' => ['বন্ধ হত', 'danger'],
        'keep' => ['বকেয়া, তবু চালু থাকত', 'warning'],
        'none' => ['Desk-এর তালিকায় নেই', 'gray'],
    ];

    public const RESULTS = [
        'match' => ['মিলেছে', 'success'],
        'desk_only' => ['শুধু Desk বন্ধ করত', 'danger'],
        'billing_only' => ['শুধু বিলিং বন্ধ করেছে', 'danger'],
        'pending' => ['বিলিং-এর লগ এখনো কপি হয়নি', 'gray'],
    ];

    public const REASONS = [
        'due_today' => 'আজ বিলের শেষ দিন পার',
        'overdue' => 'আগেই শেষ দিন পার, লাইন এখনো চালু',
        'vip' => 'VIP',
        'extended' => 'সময় বাড়ানো আছে',
        'recently_enabled' => 'কিছুদিন আগে লাইন চালু হয়েছে',
        'small_remainder' => 'পেমেন্টের পর অল্প বাকি',
        'not_due' => 'Desk-এর হিসাবে বকেয়া নেই বা শেষ দিন আসেনি',
        'not_active' => 'Active না',
        'left' => 'Left',
        'line_off' => 'সকালে লাইন আগেই বন্ধ ছিল',
    ];

    public const HINTS = [
        'billing_next_day' => 'বিলিং পরের দিন বন্ধ করেছে',
        'paid_later' => 'তালিকার পর বিল দিয়েছেন',
        'extended_in_billing' => 'বিলিং-এ সময় বাড়ানো হয়েছে',
    ];

    protected function casts(): array
    {
        return ['day' => 'date', 'due' => 'decimal:2', 'balance' => 'decimal:2', 'monthly_bill' => 'decimal:2', 'expire_on' => 'date',
            'deadline' => 'date', 'extended_until' => 'date', 'made_at' => 'datetime', 'billing_disabled_at' => 'datetime',
            'compared_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }
}
