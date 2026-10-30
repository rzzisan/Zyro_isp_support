<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\VoiceFlow;
use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Voice reminder calls by themselves (ভয়েস রিমাইন্ডার → সেটিংস), every 5 minutes (`php artisan voice:reminders`):
 *
 *  plan:     bill reminders N days before the expire date (like the SMS one, BillReminders), once per customer +
 *            expire date + N; a line seen newly off for the bill (disabled, due > 0) after line_off_delay minutes, and
 *            again on the next days while it stays off (line_off_days). Lines already off when watching started are
 *            never called (voice_line_watch.baseline). Rows wait as "planned".
 *  retry:    no answer / busy → planned again retry_minutes later, up to max_tries dials.
 *  dispatch: inside the calling hours (not Friday prayer time), as many as "একসাথে কয়টা কল" allows: checked again
 *            (paid, line back on, Left, opted out), the audio made (VoiceCalls::package), queued for the voice bot.
 *  Test mode: the calls go to the test number (with the customer's data), test_daily_max a day.
 */
class VoiceReminders
{
    public const TZ = 'Asia/Dhaka';

    public static function inWindow(VoiceReminderSetting $s, Carbon $now): bool
    {
        $at = $now->copy()->timezone(self::TZ);
        $hm = $at->format('H:i');
        if ($hm < substr((string) $s->window_from, 0, 5) || $hm >= substr((string) $s->window_to, 0, 5)) {
            return false;
        }

        return ! ($s->skip_jumma && $at->dayOfWeekIso === 5 && $hm >= '12:30' && $hm < '14:30');
    }

    /** Customers whose bill reminder call is due on $day, $n days before their expire date. */
    public static function billTargets(int $companyId, VoiceReminderSetting $s, Carbon $day, int $n): Collection
    {
        $expire = $day->copy()->startOfDay()->addDays($n);

        return BillingCustomer::where('company_id', $companyId)->whereNull('gone_at')->where('is_left', false)
            ->where('disabled', false)->whereNotNull('bill_day')->where('bill_day', '>', 0)->whereNotNull('mobile_normalized')
            ->when($s->due_only, fn ($q) => $q->where('due', '>', 0))
            ->orderBy('customer_id')->get()
            ->filter(fn ($c) => BillReminders::expireDate((int) $c->bill_day, $day)->isSameDay($expire))
            ->values();
    }

    /** Lines off for the bill now. */
    public static function offLines(int $companyId): Collection
    {
        return BillingCustomer::where('company_id', $companyId)->whereNull('gone_at')->where('is_left', false)
            ->where('disabled', true)->where('due', '>', 0)->get();
    }

    /** One pass for one company; returns what it did (for the command's log). */
    public static function run(int $companyId, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $s = VoiceReminderSetting::forCompany($companyId);
        if (! $s->exists || ! $s->enabled) {
            return [];
        }
        $lock = Cache::lock("voice-reminders:{$companyId}", 600);   // one runner per company (timer + a manual run)
        if (! $lock->get()) {
            return [];
        }
        try {
            $out = ['planned' => static::plan($companyId, $s, $now), 'expired' => static::expire($companyId, $now),
                'retried' => static::retry($companyId, $s, $now)];
            $out['queued'] = static::dispatch($companyId, $s, $now);

            return $out;
        } finally {
            $lock->release();
        }
    }

    public static function plan(int $companyId, VoiceReminderSetting $s, Carbon $now): int
    {
        $today = $now->copy()->timezone(self::TZ)->startOfDay();
        $rows = [];
        $add = function (BillingCustomer $c, string $kind, string $key, Carbon $at, Carbon $ref) use (&$rows, $companyId, $s) {
            $test = $s->test_mode;
            $rows[] = ['company_id' => $companyId, 'kind' => $kind,
                'voice_flow_id' => $kind === 'reminder' ? $s->reminder_flow_id : $s->line_off_flow_id,
                'customer_header_id' => $c->header_id, 'customer_id' => $c->customer_id, 'name' => $c->name,
                'number' => $test ? (Sms::mobile((string) $s->test_number) ?? '') : $c->mobile_normalized,
                'status' => 'planned', 'dedupe' => $key.($test ? ':test' : ''), 'ref_date' => $ref->toDateString(),
                'scheduled_at' => $at, 'created_at' => now(), 'updated_at' => now()];
        };
        if ($s->reminder_on && $s->reminder_flow_id && static::inWindow($s, $now)) {
            foreach (BillReminders::days($s->reminder_days) as $n) {
                $expire = $today->copy()->addDays($n)->toDateString();
                foreach (static::billTargets($companyId, $s, $today, $n) as $c) {
                    $add($c, 'reminder', "bill:{$c->header_id}:{$expire}:{$n}", $now, $today);
                }
            }
        }
        if ($s->line_off_on && $s->line_off_flow_id) {
            static::watchLines($companyId, $s, $now, $today, $add);
        }
        if ($s->test_mode) {   // test mode: only a few a day, to the test number
            $done = VoiceReminderCall::where('company_id', $companyId)->where('dedupe', 'like', '%:test')
                ->where('created_at', '>=', $today->copy()->utc())->count();
            $rows = Sms::mobile((string) $s->test_number) ? array_slice($rows, 0, max(0, $s->test_daily_max - $done)) : [];
        }
        $n = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            $n += DB::table('voice_reminder_calls')->insertOrIgnore($chunk);
        }

        return $n;
    }

    /** Newly off lines: a call line_off_delay minutes after we saw it, then once a day while still off. */
    private static function watchLines(int $companyId, VoiceReminderSetting $s, Carbon $now, Carbon $today, \Closure $add): void
    {
        $off = static::offLines($companyId)->keyBy('header_id');
        $watch = DB::table('voice_line_watch')->where('company_id', $companyId)->get()->keyBy('customer_header_id');
        $first = $watch->isEmpty();
        // back on: forget them, so the next time they go off is a new one
        $gone = $watch->keys()->diff($off->keys());
        if ($gone->isNotEmpty()) {
            DB::table('voice_line_watch')->where('company_id', $companyId)->whereIn('customer_header_id', $gone->all())->delete();
        }
        $new = $off->keys()->diff($watch->keys());
        DB::table('voice_line_watch')->insertOrIgnore($new->map(fn ($h) => ['company_id' => $companyId, 'customer_header_id' => $h,
            'off_seen_at' => $now, 'baseline' => $first])->values()->all());
        if ($first) {
            return;   // the lines off before watching started are never called
        }
        foreach ($new as $h) {
            $add($off[$h], 'line_off', "off:{$h}:".$today->toDateString(), $now->copy()->addMinutes($s->line_off_delay), $today);
        }
        // still off on a later day (and not reached yet): once more each day, up to line_off_days days
        $open = VoiceReminderCall::where('company_id', $companyId)->where('kind', 'line_off')
            ->where(fn ($q) => $q->whereIn('status', ['planned', 'queued', 'dialing', 'answered'])
                ->orWhere('created_at', '>=', $today->copy()->utc()))
            ->pluck('customer_header_id')->flip();
        foreach ($watch as $h => $w) {
            $seen = Carbon::parse($w->off_seen_at)->timezone(self::TZ)->startOfDay();
            if ($w->baseline || ! isset($off[$h]) || isset($open[$h]) || $seen->gte($today)
                || $seen->diffInDays($today) >= $s->line_off_days) {
                continue;
            }
            $reached = VoiceReminderCall::where('company_id', $companyId)->where('kind', 'line_off')->where('customer_header_id', $h)
                ->where('created_at', '>=', $w->off_seen_at)->where('status', 'done')->exists();
            if (! $reached) {
                $add($off[$h], 'line_off', "off:{$h}:".$today->toDateString(), $now, $today);
            }
        }
    }

    /** Planned calls whose day has passed. */
    public static function expire(int $companyId, Carbon $now): int
    {
        $today = $now->copy()->timezone(self::TZ)->toDateString();

        return VoiceReminderCall::where('company_id', $companyId)->where('status', 'planned')->where('ref_date', '<', $today)
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /** Not answered / busy today: once more retry_minutes later, up to max_tries dials. */
    public static function retry(int $companyId, VoiceReminderSetting $s, Carbon $now): int
    {
        $n = 0;
        $today = $now->copy()->timezone(self::TZ)->toDateString();
        foreach (VoiceReminderCall::where('company_id', $companyId)->whereIn('kind', ['reminder', 'line_off'])
            ->whereIn('status', ['no_answer', 'busy'])->where('tries', '<', $s->max_tries)->whereDate('ref_date', $today)
            ->where('ended_at', '<=', $now->copy()->subMinutes($s->retry_minutes))->get() as $c) {
            $c->update(['status' => 'planned', 'scheduled_at' => $now]);
            $n++;
        }

        return $n;
    }

    /** Queue as many planned calls as may ring now, each checked again and with its audio made. */
    public static function dispatch(int $companyId, VoiceReminderSetting $s, Carbon $now): int
    {
        if (! static::inWindow($s, $now)) {
            return 0;
        }
        $running = VoiceReminderCall::where('company_id', $companyId)->whereIn('status', ['queued', 'dialing', 'answered'])->count();
        $queued = 0;
        $flows = VoiceFlow::where('company_id', $companyId)->get()->keyBy('id');
        while ($running + $queued < max(1, $s->max_calls)) {
            $call = VoiceReminderCall::where('company_id', $companyId)->where('status', 'planned')->where('scheduled_at', '<=', $now)
                ->orderBy('scheduled_at')->orderBy('id')->first();
            if (! $call) {
                break;
            }
            $why = static::skipReason($call, $s, $flows->get($call->voice_flow_id));
            if ($why) {
                $call->update(['status' => 'skipped', 'reason' => $why]);

                continue;
            }
            $customer = BillingCustomer::where('company_id', $companyId)->where('header_id', $call->customer_header_id)->first();
            try {
                $call->update(['package' => VoiceCalls::package($flows->get($call->voice_flow_id), $customer), 'status' => 'queued']);
            } catch (\Throwable $e) {
                report($e);
                $call->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);

                continue;
            }
            VoiceCalls::kick($call->id);
            $queued++;
        }

        return $queued;
    }

    /** Checked right before the call: why it should not go now, or null. */
    public static function skipReason(VoiceReminderCall $call, VoiceReminderSetting $s, ?VoiceFlow $flow): ?string
    {
        if (! $flow || VoiceFlow::problems($flow->steps ?? [])) {
            return __('ফ্লো নেই বা ঠিক নেই');
        }
        if (! Sms::mobile((string) $call->number)) {
            return __('মোবাইল নম্বর নেই');
        }
        $c = BillingCustomer::where('company_id', $call->company_id)->where('header_id', $call->customer_header_id)->first();
        if (! $c || $c->gone_at || $c->is_left) {
            return __('কাস্টমার নেই বা Left');
        }
        if ($call->kind === 'reminder' && $c->disabled) {
            return __('লাইন বন্ধ');
        }
        if ($call->kind === 'reminder' && $s->due_only && (float) $c->due <= 0) {
            return __('বিল দেওয়া হয়ে গেছে');
        }
        if ($call->kind === 'line_off' && (! $c->disabled || (float) $c->due <= 0)) {
            return __('লাইন চালু হয়ে গেছে বা বিল দেওয়া হয়েছে');
        }
        if (DB::table('voice_optouts')->where('company_id', $call->company_id)
            ->whereIn('number', array_filter([$call->number, $c->mobile_normalized]))->exists()) {
            return __('আর কল চান না');
        }

        return null;
    }

    /** For the settings page: who would be called on $day and the rough minutes and cost. */
    public static function preview(int $companyId, VoiceReminderSetting $s, Carbon $day): array
    {
        $day = $day->copy()->timezone(self::TZ)->startOfDay();
        $byDays = [];
        foreach (BillReminders::days($s->reminder_days) as $n) {
            $byDays[$n] = static::billTargets($companyId, $s, $day, $n)->count();
        }
        $optout = DB::table('voice_optouts')->where('company_id', $companyId)->count();
        $total = array_sum($byDays);
        $minutes = (int) ceil($total * 1.2 * 1.3);   // ~70 s a call, some tried twice

        return ['days' => $byDays, 'total' => $total, 'minutes' => $minutes, 'cost' => round($minutes * (float) $s->minute_rate),
            'off_now' => static::offLines($companyId)->count(), 'optouts' => $optout];
    }
}
