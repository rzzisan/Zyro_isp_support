<?php

namespace App\Support;

use App\Models\BillingCustomer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A customer's other numbers for a reminder call their own number did not answer: the profile's phone, numbers
 * their tickets were opened with, WhatsApp chats linked to them, numbers the Desk sent them SMS on. Skipped: their
 * own mobile, "আর কল নয়" numbers, and numbers that belong to two or more other customers (staff, technicians,
 * shops, the office). A number that answered before comes first. Kept in customer_alt_numbers for the customer page.
 */
class AltNumbers
{
    public const SOURCES = ['profile' => 'প্রোফাইলের ফোন', 'ticket' => 'টিকেট', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];

    /** [['number' => 8801…, 'sources' => [...], 'reached_at' => ?string], ...], best first. */
    public static function for(BillingCustomer $c): Collection
    {
        $company = (int) $c->company_id;
        $h = (int) $c->header_id;
        $own = Sms::mobile((string) ($c->mobile_normalized ?: $c->mobile));
        $found = [];
        $add = function (?string $raw, string $source) use (&$found, $own) {
            $n = Sms::mobile((string) $raw);
            if ($n && $n !== $own) {
                $found[$n][$source] = true;
            }
        };
        $add($c->phone, 'profile');
        foreach (DB::table('billing_tickets')->where('company_id', $company)->where('customer_header_id', $h)->whereNotNull('mobile')->distinct()->pluck('mobile') as $m) {
            $add($m, 'ticket');
        }
        if ($c->customer_id) {
            foreach (DB::table('wa_contacts')->where('company_id', $company)->where('customer_id', $c->customer_id)->pluck('wa_number') as $m) {
                $add($m, 'whatsapp');
            }
        }
        foreach (DB::table('sms_messages')->where('company_id', $company)->where('customer_header_id', $h)->distinct()->pluck('mobile') as $m) {
            $add($m, 'sms');
        }
        $known = DB::table('customer_alt_numbers')->where('company_id', $company)->where('customer_header_id', $h)->get()->keyBy('number');
        $optout = DB::table('voice_optouts')->where('company_id', $company)->whereIn('number', array_keys($found))->pluck('number')->flip();
        $out = collect();
        foreach ($found as $n => $sources) {
            if (isset($optout[$n]) || static::othersOn($company, $h, $n) >= 2) {
                continue;
            }
            $sources = array_keys($sources);
            DB::table('customer_alt_numbers')->updateOrInsert(['company_id' => $company, 'customer_header_id' => $h, 'number' => $n],
                ['sources' => implode(',', $sources), 'seen_at' => now(), 'updated_at' => now()] + (isset($known[$n]) ? [] : ['created_at' => now()]));
            $out->push(['number' => (string) $n, 'sources' => $sources, 'reached_at' => $known[$n]->reached_at ?? null]);
        }

        // answered before first (latest first), then the profile phone, tickets, WhatsApp, SMS
        $rank = fn (array $r) => min(array_map(fn ($s) => array_search($s, array_keys(self::SOURCES), true), $r['sources']));

        return $out->sort(fn ($a, $b) => [$b['reached_at'] ? 1 : 0, (string) $b['reached_at'], -$rank($b)] <=> [$a['reached_at'] ? 1 : 0, (string) $a['reached_at'], -$rank($a)])->values();
    }

    /** How many other (current) customers this number belongs to, in any of the sources. */
    public static function othersOn(int $company, int $header, string $n): int
    {
        $forms = [$n, '0'.substr($n, 3), '+'.$n, substr($n, 3)];
        $ids = DB::table('billing_customers')->where('company_id', $company)->whereNull('gone_at')->where('is_left', false)
            ->where(fn ($q) => $q->where('mobile_normalized', $n)->orWhereIn('phone', $forms))->pluck('header_id')
            ->merge(DB::table('billing_tickets')->where('company_id', $company)->whereIn('mobile', $forms)->whereNotNull('customer_header_id')->pluck('customer_header_id'))
            ->merge(DB::table('sms_messages')->where('company_id', $company)->where('mobile', $n)->whereNotNull('customer_header_id')->pluck('customer_header_id'));
        $wa = DB::table('wa_contacts')->where('company_id', $company)->where('wa_number', $n)->whereNotNull('customer_id')->pluck('customer_id');
        if ($wa->isNotEmpty()) {
            $ids = $ids->merge(DB::table('billing_customers')->where('company_id', $company)->whereIn('customer_id', $wa->all())->pluck('header_id'));
        }

        return $ids->map(fn ($v) => (int) $v)->unique()->reject(fn ($v) => $v === $header)->count();
    }

    /** "০১৭১… (টিকেট, SMS)" lines for the customer page. */
    public static function label(array $r): string
    {
        return '0'.substr($r['number'], 3).' ('.implode(', ', array_map(fn ($s) => __(self::SOURCES[$s] ?? $s), $r['sources'])).')';
    }
}
