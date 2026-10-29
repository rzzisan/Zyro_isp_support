<?php

namespace App\Support;

use App\Models\Membership;
use Illuminate\Support\Carbon;

/**
 * When a member's phone rings for calls (hotline, WhatsApp, a reminder call's "স্টাফের সাথে কথা"): their duty hours
 * from the team page (no hours = any time) and the app's "কল নিচ্ছি" switch. engine/voice_bot.py on_call_duty() is the
 * same rule for the app push; the web popup still shows every ringing call.
 */
class CallDuty
{
    /** ISO weekday => name, the week as Bangladesh offices count it. */
    public const DAYS = [6 => 'শনি', 7 => 'রবি', 1 => 'সোম', 2 => 'মঙ্গল', 3 => 'বুধ', 4 => 'বৃহস্পতি', 5 => 'শুক্র'];

    public static function inHours(?array $hours, ?Carbon $at = null): bool
    {
        $days = array_map('intval', $hours['days'] ?? []);
        $from = $hours['from'] ?? null;
        $to = $hours['to'] ?? null;
        if (! $days && ! $from && ! $to) {
            return true;
        }
        $at = ($at ?? now())->copy()->timezone('Asia/Dhaka');
        $hm = $at->format('H:i');
        $from = substr((string) ($from ?: '00:00'), 0, 5);
        $to = substr((string) ($to ?: '24:00'), 0, 5);
        // a night shift (22:00 to 06:00) belongs to the day it starts
        $day = $from > $to && $hm < $to ? $at->copy()->subDay()->dayOfWeekIso : $at->dayOfWeekIso;
        if ($days && ! in_array($day, $days, true)) {
            return false;
        }

        return $from <= $to ? ($hm >= $from && $hm < $to) : ($hm >= $from || $hm < $to);
    }

    public static function taking(Membership $m, ?Carbon $at = null): bool
    {
        return ! $m->calls_paused && static::inHours($m->call_hours, $at);
    }

    /** "শনি-বৃহস্পতি 10:00-20:00" style text for the team page and the app. */
    public static function label(?array $hours): string
    {
        $days = array_map('intval', $hours['days'] ?? []);
        $from = $hours['from'] ?? null;
        $to = $hours['to'] ?? null;
        if (! $days && ! $from && ! $to) {
            return __('সবসময়');
        }
        $names = $days && count($days) < 7
            ? implode(', ', array_values(array_intersect_key(self::DAYS, array_flip($days))))
            : __('প্রতিদিন');

        return trim($names.' '.substr((string) ($from ?: '00:00'), 0, 5).'-'.substr((string) ($to ?: '24:00'), 0, 5));
    }
}
