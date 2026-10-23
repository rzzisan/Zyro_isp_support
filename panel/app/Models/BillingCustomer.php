<?php

namespace App\Models;

use App\Services\Engine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Customer search used everywhere in the desk (customer list, header search, pickers, app): the customer ID, any
     * part of the PPPoE user or name (case-insensitive; with several words each word must match, in any order), or
     * the mobile written any way: 01711-223344, +880 1711 223344, 8801711223344 or just its last digits.
     */
    public function scopeMatching(Builder $query, string $search): Builder
    {
        $q = trim(preg_replace('/\s+/u', ' ', mb_substr($search, 0, 60)));
        if ($q === '') {
            return $query;
        }
        $like = fn (string $s) => '%'.addcslashes($s, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $q);

        return $query->where(function (Builder $w) use ($q, $like, $digits) {
            $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$q])->orWhere('customer_id', 'ilike', $like($q))
                ->orWhere('username', 'ilike', $like($q))->orWhere('name', 'ilike', $like($q));
            $words = explode(' ', $q);
            if (count($words) > 1) {
                $w->orWhere(function (Builder $all) use ($words, $like) {
                    foreach ($words as $word) {
                        $all->where(fn (Builder $one) => $one->where('name', 'ilike', $like($word))->orWhere('username', 'ilike', $like($word)));
                    }
                });
            }
            // a phone number: drop the country code and the leading 0, then match the rest anywhere in the mobile
            $core = preg_replace('/^(?:88)?0?/', '', $digits);
            if (strlen($core) >= 4 && strlen($digits) * 2 >= strlen(str_replace(' ', '', $q))) {
                $w->orWhere('mobile_normalized', 'like', "%{$core}%")
                    ->orWhereRaw("regexp_replace(coalesce(mobile, ''), ?, '', 'g') like ?", ['\D', "%{$core}%"]);
            }
        });
    }

    /**
     * Header search, like the billing software's: any part of the customer ID, PPPoE user, name or mobile. Reads the
     * desk's own copy; when that has nobody, asks billing's header search and copies the customers we don't have.
     * Left customers are included after the current ones. Shared by the panel's header search and the app.
     */
    public static function headerSearch(int $companyId, string $search, int $limit = 30): Collection
    {
        $q = trim(mb_substr($search, 0, 60));
        if ($q === '') {
            return collect();
        }
        $base = fn () => static::where('company_id', $companyId)->whereNull('gone_at');
        $rows = $base()->matching($q)
            ->orderByRaw("(ltrim(customer_id, '0') = ltrim(?, '0') OR lower(username) = lower(?)) DESC", [$q, $q])
            ->orderBy('is_left')->orderByRaw("CASE WHEN customer_id ~ '^[0-9]{1,9}$' THEN customer_id::bigint END")
            ->limit($limit)->get();

        if ($rows->isEmpty() && mb_strlen($q) >= 3) {
            $ids = Cache::remember('header-search:'.$companyId.':'.mb_strtolower($q), 300,
                fn () => Engine::findInBilling($companyId, $q));
            $rows = $ids ? $base()->whereIn('header_id', $ids)->orderBy('is_left')->limit($limit)->get() : $rows;
        }

        return $rows;
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
