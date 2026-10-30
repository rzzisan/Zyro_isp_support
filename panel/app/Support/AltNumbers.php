<?php

namespace App\Support;

use App\Models\BillingCustomer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customers' other numbers (customer_alt_numbers): the profile's phone, numbers their tickets were opened with,
 * WhatsApp chats linked to them, numbers the Desk sent them SMS on, and numbers staff added (কাস্টমার ও বিল →
 * বিকল্প নম্বর). Skipped: their own mobile, and numbers that belong to two or more other customers (staff,
 * technicians, shops, the office), unless staff added it. A number staff removed stays off for good.
 * Used by: the reminder calls when the own number is not answered (for(), best first, "আর কল নয়" left out), the
 * customer search (BillingCustomer::scopeMatching) and the bots (engine/customers.py local_by_whatsapp).
 * rebuild() redoes a company's list each night (`php artisan customers:alt-numbers`); refresh() one customer.
 */
class AltNumbers
{
    public const SOURCES = ['staff' => 'স্টাফ যোগ করেছেন', 'profile' => 'প্রোফাইলের ফোন', 'ticket' => 'টিকেট', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];

    /** Found numbers: [header_id => [number => [source => true]]] for $headers (null = everyone). */
    private static function found(int $company, ?array $headers): array
    {
        $out = [];
        $add = function ($h, ?string $raw, string $source) use (&$out) {
            if ($h && ($n = Sms::mobile((string) $raw))) {
                $out[(int) $h][$n][$source] = true;
            }
        };
        $only = fn ($q, string $col) => $headers === null ? $q : $q->whereIn($col, $headers);
        foreach ($only(DB::table('billing_customers')->where('company_id', $company)->whereNotNull('phone'), 'header_id')->get(['header_id', 'phone']) as $r) {
            $add($r->header_id, $r->phone, 'profile');
        }
        foreach ($only(DB::table('billing_tickets')->where('company_id', $company)->whereNotNull('mobile')->whereNotNull('customer_header_id'), 'customer_header_id')
            ->distinct()->get(['customer_header_id', 'mobile']) as $r) {
            $add($r->customer_header_id, $r->mobile, 'ticket');
        }
        $ids = $only(DB::table('billing_customers')->where('company_id', $company)->whereNotNull('customer_id'), 'header_id')->pluck('header_id', 'customer_id');
        foreach (DB::table('wa_contacts')->where('company_id', $company)->whereNotNull('customer_id')
            ->when($headers !== null, fn ($q) => $q->whereIn('customer_id', $ids->keys()->all()))->get(['customer_id', 'wa_number']) as $r) {
            $add($ids[$r->customer_id] ?? null, $r->wa_number, 'whatsapp');
        }
        foreach ($only(DB::table('sms_messages')->where('company_id', $company)->whereNotNull('customer_header_id'), 'customer_header_id')
            ->distinct()->get(['customer_header_id', 'mobile']) as $r) {
            $add($r->customer_header_id, $r->mobile, 'sms');
        }

        return $out;
    }

    /** How many other customers each number belongs to: [number => [header_id => true]] (own mobiles of current customers, and $found). */
    private static function owners(int $company, array $numbers, array $found): array
    {
        $owners = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            foreach (DB::table('billing_customers')->where('company_id', $company)->whereNull('gone_at')->where('is_left', false)
                ->whereIn('mobile_normalized', $chunk)->get(['header_id', 'mobile_normalized']) as $r) {
                $owners[$r->mobile_normalized][(int) $r->header_id] = true;
            }
        }
        foreach ($found as $h => $nums) {
            foreach ($nums as $n => $_) {
                $owners[$n][$h] = true;
            }
        }

        return $owners;
    }

    /** Same as owners(), for a few numbers, looked up by number in every source. */
    private static function ownersOf(int $company, array $numbers): array
    {
        $owners = [];
        foreach ($numbers as $n) {
            $forms = [$n, '0'.substr($n, 3), '+'.$n, substr($n, 3)];
            $ids = DB::table('billing_customers')->where('company_id', $company)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('mobile_normalized', $n)->whereNull('gone_at')->where('is_left', false))
                    ->orWhereIn('phone', $forms))->pluck('header_id')
                ->merge(DB::table('billing_tickets')->where('company_id', $company)->whereIn('mobile', $forms)->whereNotNull('customer_header_id')->pluck('customer_header_id'))
                ->merge(DB::table('sms_messages')->where('company_id', $company)->where('mobile', $n)->whereNotNull('customer_header_id')->pluck('customer_header_id'));
            $wa = DB::table('wa_contacts')->where('company_id', $company)->where('wa_number', $n)->whereNotNull('customer_id')->pluck('customer_id');
            if ($wa->isNotEmpty()) {
                $ids = $ids->merge(DB::table('billing_customers')->where('company_id', $company)->whereIn('customer_id', $wa->all())->pluck('header_id'));
            }
            foreach ($ids as $h) {
                $owners[$n][(int) $h] = true;
            }
        }

        return $owners;
    }

    /** Write the found numbers of $headers (null = the whole company); numbers no longer found go, staff's stay. */
    private static function write(int $company, ?array $headers): int
    {
        $found = static::found($company, $headers);
        // numbers are array keys here, so PHP made them ints: back to strings for the varchar columns
        $numbers = collect($found)->flatMap(fn ($nums) => array_keys($nums))->map(fn ($n) => (string) $n)->unique()->values()->all();
        // one customer: the owner count needs every customer the numbers appear on, not only this one
        $owners = $headers === null ? static::owners($company, $numbers, $found) : static::ownersOf($company, $numbers);
        $own = DB::table('billing_customers')->where('company_id', $company)
            ->when($headers !== null, fn ($q) => $q->whereIn('header_id', $headers))->pluck('mobile_normalized', 'header_id');
        $existing = DB::table('customer_alt_numbers')->where('company_id', $company)
            ->when($headers !== null, fn ($q) => $q->whereIn('customer_header_id', $headers))
            ->get(['id', 'customer_header_id', 'number', 'sources'])->keyBy(fn ($r) => $r->customer_header_id.':'.$r->number);
        $keep = [];
        $rows = [];
        foreach ($found as $h => $nums) {
            foreach ($nums as $n => $sources) {
                $n = (string) $n;
                if ($n === ($own[$h] ?? null) || count($owners[$n] ?? []) - 1 >= 2) {
                    continue;
                }
                $key = "{$h}:{$n}";
                $old = $existing[$key] ?? null;
                $staff = $old && in_array('staff', explode(',', (string) $old->sources), true);
                $list = implode(',', array_keys(($staff ? ['staff' => true] : []) + $sources));
                $keep[$key] = true;
                if (! $old) {
                    $rows[] = ['company_id' => $company, 'customer_header_id' => $h, 'number' => $n, 'sources' => $list,
                        'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                } elseif ($old->sources !== $list) {
                    DB::table('customer_alt_numbers')->where('id', $old->id)->update(['sources' => $list, 'seen_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customer_alt_numbers')->insertOrIgnore($chunk);
        }
        // not found any more (now shared, or the ticket/chat moved): gone, unless staff added or removed it
        $gone = $existing->reject(fn ($r, $key) => isset($keep[$key]) || in_array('staff', explode(',', (string) $r->sources), true))->pluck('id');
        foreach ($gone->chunk(500) as $ids) {
            DB::table('customer_alt_numbers')->whereIn('id', $ids->all())->whereNull('removed_at')->delete();
        }

        return count($rows);
    }

    /** The whole company's list (nightly); returns how many numbers are new. */
    public static function rebuild(int $company): int
    {
        return static::write($company, null);
    }

    public static function refresh(BillingCustomer $c): void
    {
        static::write((int) $c->company_id, [(int) $c->header_id]);
    }

    /** For a reminder call: the list brought up to date, "আর কল নয়" numbers left out. */
    public static function for(BillingCustomer $c): Collection
    {
        static::refresh($c);
        $optout = DB::table('voice_optouts')->where('company_id', $c->company_id)->pluck('number')->flip();

        return static::list($c)->reject(fn ($r) => isset($optout[$r['number']]))->values();
    }

    /** What is kept for the customer: [['number' => 8801…, 'sources' => [...], 'reached_at' => ?string], ...], best first. */
    public static function list(BillingCustomer $c): Collection
    {
        $company = (int) $c->company_id;
        $rows = DB::table('customer_alt_numbers')->where('company_id', $company)->where('customer_header_id', $c->header_id)->whereNull('removed_at')->get();
        $rank = fn (array $r) => min(array_map(fn ($s) => (int) array_search($s, array_keys(self::SOURCES), true), $r['sources'] ?: ['sms']));

        // answered before first (latest first), then staff-added, the profile phone, tickets, WhatsApp, SMS
        return $rows->map(fn ($r) => ['number' => (string) $r->number, 'sources' => array_values(array_filter(explode(',', (string) $r->sources))), 'reached_at' => $r->reached_at])
            ->sort(fn ($a, $b) => [$b['reached_at'] ? 1 : 0, (string) $b['reached_at'], -$rank($b)] <=> [$a['reached_at'] ? 1 : 0, (string) $a['reached_at'], -$rank($a)])
            ->values();
    }

    /** "০১৭১… (টিকেট, SMS)" lines for the customer page. */
    public static function label(array $r): string
    {
        return '0'.substr($r['number'], 3).' ('.implode(', ', array_map(fn ($s) => __(self::SOURCES[$s] ?? $s), $r['sources'])).')';
    }
}
