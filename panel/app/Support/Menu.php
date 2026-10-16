<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Per-member menu permissions. The owner always sees everything. Others see their own list
 * (company_user.permissions) or, when none is set, the defaults of their role.
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
        'bills' => ['বিল কালেকশন', false, false],
        'sms' => ['SMS (পাঠানো, লগ, টেমপ্লেট)', false, false],
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
        return collect(self::ITEMS)->filter(fn ($i) => $settings || ! $i[2])->map(fn ($i) => $i[0])->all();
    }

    public static function defaults(string $role): array
    {
        return array_keys(array_filter(self::ITEMS, fn ($i) => in_array($role, ['owner', 'admin'], true) || $i[1]));
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
        if ($m->role === 'owner') {
            return true;
        }
        if ((self::ITEMS[$key][2] ?? false) && $m->role !== 'admin') {
            return false;
        }

        return in_array($key, $m->permissions ?? self::defaults($m->role), true);
    }

    /** For canAccess() of a page/resource in the company panel. */
    public static function can(string $key): bool
    {
        return self::allows(auth()->user(), Filament::getTenant(), $key);
    }
}
