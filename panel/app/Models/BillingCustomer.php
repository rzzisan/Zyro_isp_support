<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
            'synced_at' => 'datetime', 'gone_at' => 'datetime', 'is_left' => 'boolean', 'left_on' => 'date', 'details_fetched_at' => 'datetime',
            'details' => 'array', 'billing_start_month' => 'date', 'birth_date' => 'date', 'last_login_at' => 'datetime',
        ];
    }

    /** Customer search dropdown label: "ID 0003 · Bulbul · en.enamul · 0171… · Zone / Subzone / Box", empty parts skipped. */
    public static function searchLabel(array|self $c): string
    {
        $c = $c instanceof self ? $c->only(['customer_id', 'name', 'username', 'mobile', 'zone', 'subzone', 'box', 'is_left', 'left_on']) : $c;
        $place = collect([$c['zone'] ?? null, $c['subzone'] ?? null, $c['box'] ?? null])->map(fn ($v) => trim((string) $v))->filter()->join(' / ');
        $label = collect(['ID '.($c['customer_id'] ?? ''), $c['name'] ?? null, $c['username'] ?? null, $c['mobile'] ?? null, $place])
            ->map(fn ($v) => trim((string) $v))->filter()->join(' · ');
        if (! empty($c['is_left'])) {
            $label .= ' · LEFT'.(! empty($c['left_on']) ? ' ('.Carbon::parse($c['left_on'])->format('d M Y').')' : '');
        }

        return $label;
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

    /** Its PPPoE session seen on a MikroTik in the last few minutes (online now, not on a block profile), or null. */
    public function onlineSession(): ?PppSession
    {
        return $this->username ? PppSession::where('company_id', $this->company_id)->where('username', $this->username)
            ->where('seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))->where('blocked', false)->first() : null;
    }
}
