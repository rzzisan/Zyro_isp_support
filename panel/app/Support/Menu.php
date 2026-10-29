<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Per-member menu permissions. The owner always sees everything. Others see their own list
 * (company_user.permissions) plus the menus of every permission group they are in. With no own list set, a member
 * in no group gets the defaults of their role; a member in a group gets only the groups' menus.
 * Settings menus additionally need the owner/admin role, so ticking them for an agent has no effect.
 */
class Menu
{
    /** key => [label, agent default, settings (owner/admin only)] */
    public const ITEMS = [
        'inbox' => ['ইনবক্স', true, false],
        'customers' => ['কাস্টমার', true, false],
        'monitoring' => ['অনলাইন মনিটরিং', true, false],
        'tickets' => ['টিকিট', true, false],
        'calls' => ['হটলাইন কল লগ (রেকর্ডিং)', true, false],
        'phone' => ['ফোন: WhatsApp কল ধরা ও কাস্টমারকে কল', false, false],
        // field technicians in the app: only their own tickets and those customers (needs the billing employee on the team page)
        'field_jobs' => ['আমার কাজ (ফিল্ড টেকনিশিয়ান)', false, false],
        'force_solve' => ['লাইন অফলাইন থাকলেও কারণ লিখে কাজ শেষ', false, false],
        'bills' => ['বিল কালেকশন', false, false],
        'sms' => ['SMS (পাঠানো, লগ, টেমপ্লেট)', false, false],
        'wa_campaign' => ['WhatsApp টেমপ্লেট পাঠানো ও লগ', false, false],
        'voice_reminder' => ['ভয়েস রিমাইন্ডার (টেমপ্লেট, কল ফ্লো, সেটিংস)', false, true],
        'line_enables' => ['লাইন চালু/বন্ধের রেকর্ড', true, false],
        'line_switch' => ['কাস্টমার লিস্টে লাইন চালু/বন্ধ', false, false],
        'onus' => ['ONU মনিটরিং', true, false],
        // off for agents until the cutover day from the old zisan.me/onu app (no entries in two places); owner can tick it per member
        'onu_stock' => ['ONU স্টক, নতুন সংযোগ ও অন্যান্য মাল', false, false],
        'whatsapp' => ['WhatsApp সেটিং', false, true],
        'billing' => ['বিলিং সংযোগ', false, true],
        'billing_config' => ['বিলিং কনফিগারেশন', false, true],
        'mikrotik' => ['MikroTik', false, true],
        'olt' => ['OLT', false, true],
        'bot' => ['বট সেটিংস', false, true],
        'ai_keys' => ['AI key', false, true],
        'team' => ['টিম', false, true],
        'technicians' => ['টেকনিশিয়ান', false, true],
    ];

    public static function options(bool $settings = true): array
    {
        return collect(self::ITEMS)->except(self::EXPLICIT)->filter(fn ($i) => $settings || ! $i[2])->map(fn ($i) => __($i[0]))->all();
    }

    /** A saved list as the checkboxes can show it: keys no longer offered (like the old "আমার কাজ") are dropped. */
    public static function selectable(array $keys): array
    {
        return array_values(array_intersect($keys, array_keys(self::options())));
    }

    /** Not a menu to tick: "আমার কাজ" comes with the member's "ফিল্ড টেকনিশিয়ান" tick on the team page, whatever the role. */
    public const EXPLICIT = ['field_jobs'];

    public static function defaults(string $role): array
    {
        return array_values(array_diff(array_keys(array_filter(self::ITEMS, fn ($i) => in_array($role, ['owner', 'admin'], true) || $i[1])),
            self::EXPLICIT));
    }

    public static function allows(?User $user, ?Company $company, string $key): bool
    {
        if (! $user || ! $company) {
            return false;
        }
        $m = Membership::where('company_id', $company->getKey())->where('user_id', $user->getKey())->first();
        if (! $m) {
            return false;
        }
        if (in_array($key, self::EXPLICIT, true)) {
            return (bool) $m->field_technician;   // "আমার কাজ": the members ticked as field technicians only
        }
        if ($m->role === 'owner') {
            return true;
        }
        if ((self::ITEMS[$key][2] ?? false) && $m->role !== 'admin') {
            return false;
        }

        return in_array($key, self::keys($m), true);
    }

    /** The menus a member's own list and groups give (before the owner/admin rules of allows()). */
    public static function keys(Membership $m): array
    {
        $groups = ($m->relationLoaded('groups') ? $m->groups : $m->groups()->get())->pluck('permissions')->flatten()->all();
        $own = $m->permissions ?? ($groups ? [] : self::defaults($m->role));

        return array_values(array_intersect(array_keys(self::ITEMS), [...$own, ...$groups]));
    }

    /** For canAccess() of a page/resource in the company panel. */
    public static function can(string $key): bool
    {
        return self::allows(auth()->user(), Filament::getTenant(), $key);
    }
}
