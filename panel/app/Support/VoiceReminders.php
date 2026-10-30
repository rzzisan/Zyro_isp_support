<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\VoiceFlow;
use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
use App\Models\VoiceTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *  settle:   a call whose tries are over and nobody answered: the customer's other numbers (AltNumbers), one at a
 *            time, up to alt_max a day; then the call is settled (reached or not).
 *  backup:   customers who regularly answer (2 of their last 3 calls) get no bill reminder SMS while a call is coming
 *            (BillReminders asks coversSms); when that call is settled unreached, the SMS goes now as a backup.
 *  Test mode: the calls go to the test number (with the customer's data), test_daily_max a day.
 *  inbound:  every full pass (even while the calls are off) the line-off flow's package is made ahead for each line
 *            off for the bill (prepareInbound), so when such a customer calls the hotline or WhatsApp the flow picks
 *            the call up within seconds (engine/voice_ivr.py inbound). In test mode only the test number gets it.
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

    /**
     * One pass for one company; returns what it did (for the command's log). $full false: only what moves the queue
     * (retry, settle, backup SMS, dispatch), for the quick passes between calls (`voice:reminders --for=55`).
     */
    public static function run(int $companyId, ?Carbon $now = null, bool $full = true): array
    {
        $now = ($now ?? now())->copy();
        $s = VoiceReminderSetting::forCompany($companyId);
        if (! $s->exists) {
            return [];
        }
        $lock = Cache::lock("voice-reminders:{$companyId}", 600);   // one runner per company (timer + a manual run)
        if (! $lock->get()) {
            return [];
        }
        try {
            if (! $s->enabled) {   // turned off: what was waiting is over, the backup SMS still go
                $out = ['settled' => static::settle($companyId, $s, $now, false), 'backup_sms' => static::backup($companyId)];
            } else {
                $out = $full ? ['planned' => static::plan($companyId, $s, $now), 'expired' => static::expire($companyId, $now)] : [];
                $out['retried'] = static::retry($companyId, $s, $now);
                $out['settled'] = static::settle($companyId, $s, $now, true);
                $out['backup_sms'] = static::backup($companyId);
                $out['queued'] = static::dispatch($companyId, $s, $now);
            }
            // after the calls are queued, so they never wait for this audio
            $out['inbound_ready'] = $full ? static::prepareInbound($companyId, $s) : 0;

            return $s->enabled ? $out : array_filter($out);
        } finally {
            $lock->release();
        }
    }

    /**
     * The line-off flow's package for every line off for the bill (the test mode's example only, in test mode), made
     * again only when what the caller would hear or get changes (the flow, a message's text for this customer, the
     * SMS texts); the rest removed. New audio is made $concurrency at a time, for at most $seconds a pass; the rest
     * next minute. Returns how many were made.
     */
    public static function prepareInbound(int $companyId, VoiceReminderSetting $s, int $seconds = 40, int $concurrency = 4): int
    {
        $ready = DB::table('voice_inbound_ready')->where('company_id', $companyId);
        $flowId = $s->inbound_flow_id ?: $s->line_off_flow_id;
        $flow = $s->inbound_on && $flowId ? VoiceFlow::where('company_id', $companyId)->find($flowId) : null;
        if (! $flow) {
            $ready->delete();

            return 0;
        }
        $templates = VoiceTemplate::where('company_id', $companyId)->whereIn('id', $flow->templateIds())->get();
        $smsTemplates = SmsTemplate::where('company_id', $companyId)->whereIn('id', $flow->smsTemplateIds())->get();
        $base = json_encode([$flow->id, $flow->steps, $flow->hold, $flow->goodbye_template_id, $flow->name]);
        $targets = $s->test_mode ? collect([0 => null]) : static::offLines($companyId)->keyBy('header_id');
        $have = (clone $ready)->pluck('signature', 'customer_header_id');
        $gone = array_diff($have->keys()->all(), $targets->keys()->all());
        foreach (array_chunk($gone, 500) as $chunk) {
            (clone $ready)->whereIn('customer_header_id', $chunk)->delete();
        }
        // what changed, never made first
        $todo = [];
        foreach ($targets as $header => $c) {
            $messages = VoiceCalls::messages($flow, $c, [], $templates);
            $sms = $smsTemplates->map(fn ($t) => Sms::render($t->body, $c, ['company' => Sms::companyName($companyId)]))->all();
            $sig = sha1($base.json_encode([$messages, $sms, $c?->name, $c?->customer_id]));
            if (($have[$header] ?? null) !== $sig) {
                $todo[] = [$header, $c, $messages, $sig, isset($have[$header])];
            }
        }
        usort($todo, fn ($a, $b) => $a[4] <=> $b[4]);
        $start = microtime(true);
        $made = 0;
        foreach (array_chunk($todo, max(1, $concurrency)) as $batch) {
            if (microtime(true) - $start > $seconds) {
                break;
            }
            $texts = [];
            foreach ($batch as [, , $messages]) {
                foreach ($messages as $m) {
                    if (! isset($m['upload']) && ! $m['later']) {
                        $texts[VoiceCalls::audioKey($m)] = $m;
                    }
                }
            }
            $audio = VoiceTemplates::audioMany($companyId, $texts, $concurrency);
            foreach ($batch as [$header, $c, $messages, $sig]) {
                try {
                    $package = VoiceCalls::package($flow, $c, [], $audio, $messages);
                } catch (\Throwable $e) {
                    Log::warning("voice inbound package {$companyId}/{$header}: ".$e->getMessage());

                    continue;
                }
                DB::table('voice_inbound_ready')->upsert([[
                    'company_id' => $companyId, 'customer_header_id' => $header, 'voice_flow_id' => $flow->id, 'signature' => $sig,
                    'package' => json_encode($package, JSON_UNESCAPED_UNICODE), 'built_at' => now(),
                ]], ['company_id', 'customer_header_id'], ['voice_flow_id', 'signature', 'package', 'built_at']);
                $made++;
            }
        }

        return $made;
    }

    public static function plan(int $companyId, VoiceReminderSetting $s, Carbon $now): int
    {
        $today = $now->copy()->timezone(self::TZ)->startOfDay();
        $rows = [];
        $add = function (BillingCustomer $c, string $kind, string $key, Carbon $at, Carbon $ref, bool $smsBackup = false) use (&$rows, $companyId, $s) {
            $test = $s->test_mode;
            $rows[] = ['company_id' => $companyId, 'kind' => $kind,
                'voice_flow_id' => $kind === 'reminder' ? $s->reminder_flow_id : $s->line_off_flow_id,
                'customer_header_id' => $c->header_id, 'customer_id' => $c->customer_id, 'name' => $c->name,
                'number' => $test ? (Sms::mobile((string) $s->test_number) ?? '') : $c->mobile_normalized,
                'status' => 'planned', 'dedupe' => $key.($test ? ':test' : ''), 'ref_date' => $ref->toDateString(),
                'sms_backup' => $smsBackup && ! $test,
                'scheduled_at' => $at, 'created_at' => now(), 'updated_at' => now()];
        };
        if ($s->reminder_on && $s->reminder_flow_id && static::inWindow($s, $now)) {
            $byDay = [];
            foreach (BillReminders::days($s->reminder_days) as $n) {
                $byDay[$n] = static::billTargets($companyId, $s, $today, $n);
            }
            $regular = $s->sms_saving && ! $s->test_mode
                ? static::regulars($companyId, collect($byDay)->flatten()->pluck('header_id')->all()) : [];
            foreach ($byDay as $n => $targets) {
                $expire = $today->copy()->addDays($n)->toDateString();
                foreach ($targets as $c) {
                    $add($c, 'reminder', "bill:{$c->header_id}:{$expire}:{$n}", $now, $today, isset($regular[$c->header_id]));
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
            ->whereNull('alt_of')->whereIn('status', ['no_answer', 'busy'])->where('tries', '<', $s->max_tries)->whereDate('ref_date', $today)
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
            // on another number: the template's {called_for} says whose connection it is about
            $extra = $call->alt_of ? ['called_for' => __(':name-এর ইন্টারনেট সংযোগের ব্যাপারে কল করেছি, অনুগ্রহ করে তাঁকে জানিয়ে দেবেন। ', ['name' => $customer->name])] : [];
            try {
                $call->update(['package' => VoiceCalls::package($flows->get($call->voice_flow_id), $customer, $extra), 'status' => 'queued']);
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

    /**
     * Calls whose dialling is over: answered (by this or another number) → reached; not answered and no retry left →
     * the next of the customer's other numbers (when on, inside the hours, up to alt_max a day), else settled unreached.
     * $active false (reminders turned off): no more retries or other numbers, just settle.
     */
    public static function settle(int $companyId, VoiceReminderSetting $s, Carbon $now, bool $active): int
    {
        $today = $now->copy()->timezone(self::TZ)->toDateString();
        $open = ['planned', 'queued', 'dialing', 'answered'];
        $hoursLeft = $now->copy()->timezone(self::TZ)->format('H:i') < substr((string) $s->window_to, 0, 5);
        $n = 0;
        foreach (VoiceReminderCall::where('company_id', $companyId)->whereIn('kind', ['reminder', 'line_off'])->whereNull('alt_of')
            ->whereNull('settled_at')->whereNotIn('status', $open)->orderBy('id')->get() as $m) {
            $alts = VoiceReminderCall::where('alt_of', $m->id)->orderBy('id')->get();
            $answered = $alts->first(fn ($a) => $a->answered_at);
            if ($m->answered_at || $answered) {
                if ($answered && ! str_ends_with((string) $m->dedupe, ':test')) {
                    DB::table('customer_alt_numbers')->where('company_id', $companyId)->where('customer_header_id', $m->customer_header_id)
                        ->where('number', $answered->alt_number)->update(['reached_at' => $answered->answered_at, 'updated_at' => now()]);
                }
                $m->update(['settled_at' => $now, 'reached' => true]);
                $n++;

                continue;
            }
            if ($alts->contains(fn ($a) => in_array($a->status, $open, true))) {
                continue;
            }
            $sameDay = $m->ref_date?->toDateString() === $today;
            if ($active && $sameDay && $hoursLeft && in_array($m->status, ['no_answer', 'busy'], true) && $m->tries < $s->max_tries) {
                continue;   // retry() puts it back later today
            }
            if ($active && $sameDay && $hoursLeft && $s->alt_on && in_array($m->status, ['no_answer', 'busy', 'failed'], true)
                && static::nextAlt($m, $alts, $s, $now)) {
                continue;
            }
            $m->update(['settled_at' => $now, 'reached' => false]);
            $n++;
        }

        return $n;
    }

    /** Plan the call to the customer's next other number; false when there is none (left) for today. */
    private static function nextAlt(VoiceReminderCall $m, Collection $alts, VoiceReminderSetting $s, Carbon $now): bool
    {
        $c = BillingCustomer::where('company_id', $m->company_id)->where('header_id', $m->customer_header_id)->first();
        if (! $c) {
            return false;
        }
        $start = $now->copy()->timezone(self::TZ)->startOfDay()->utc();
        $todayAlts = VoiceReminderCall::where('company_id', $m->company_id)->where('customer_header_id', $c->header_id)
            ->whereNotNull('alt_of')->where('created_at', '>=', $start)->count();
        if ($todayAlts >= max(1, (int) $s->alt_max)) {
            return false;
        }
        $tried = $alts->pluck('alt_number')->all();
        $next = AltNumbers::for($c)->first(fn ($r) => ! in_array($r['number'], $tried, true));
        if (! $next) {
            return false;
        }
        $test = str_ends_with((string) $m->dedupe, ':test');
        if ($test) {   // a test: to the test number, inside the test day's limit
            $done = VoiceReminderCall::where('company_id', $m->company_id)->where('dedupe', 'like', '%:test')->where('created_at', '>=', $start)->count();
            $number = Sms::mobile((string) $s->test_number);
            if (! $s->test_mode || ! $number || $done >= $s->test_daily_max) {
                return false;
            }
        }
        $base = $test ? substr((string) $m->dedupe, 0, -5) : (string) $m->dedupe;
        VoiceReminderCall::create(['company_id' => $m->company_id, 'kind' => $m->kind, 'voice_flow_id' => $m->voice_flow_id,
            'customer_header_id' => $c->header_id, 'customer_id' => $c->customer_id, 'name' => $c->name,
            'number' => $test ? $number : $next['number'], 'alt_of' => $m->id, 'alt_number' => $next['number'],
            'number_source' => implode(',', $next['sources']), 'status' => 'planned',
            'dedupe' => $base.':alt:'.$next['number'].($test ? ':test' : ''), 'ref_date' => $m->ref_date, 'scheduled_at' => $now]);
        if (! $test) {
            DB::table('customer_alt_numbers')->where('company_id', $m->company_id)->where('customer_header_id', $c->header_id)
                ->where('number', $next['number'])->update(['tried_at' => now()]);
        }

        return true;
    }

    /** The bill reminder SMS for regular answerers whose call got nobody (once per expire date and day). */
    public static function backup(int $companyId): int
    {
        $rows = VoiceReminderCall::where('company_id', $companyId)->where('kind', 'reminder')->whereNull('alt_of')->where('sms_backup', true)
            ->where('reached', false)->whereNotNull('settled_at')->whereNull('backup_sms_at')->where('created_at', '>=', now()->subDays(3))->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $sms = SmsSetting::forCompany($companyId);
        $n = 0;
        foreach ($rows as $r) {
            $r->update(['backup_sms_at' => now()]);
            if (! preg_match('/^bill:(\d+):(\d{4}-\d{2}-\d{2}):(\d+)$/', (string) $r->dedupe, $m) || ! $sms->eventOn('bill_reminder')) {
                continue;
            }
            $c = BillingCustomer::where('company_id', $companyId)->where('header_id', (int) $m[1])->first();
            if (! $c || $c->gone_at || $c->is_left || $c->disabled || ($sms->reminder_due_only && (float) $c->due <= 0)
                || DB::table('sms_reminders')->where('company_id', $companyId)->where('customer_header_id', $c->header_id)
                    ->where('expire_date', $m[2])->where('days_before', (int) $m[3])->exists()) {
                continue;
            }
            $id = BillReminders::send($companyId, $sms, collect([$c]), Carbon::parse($m[2], self::TZ), (int) $m[3]);
            if ($id) {
                $r->update(['backup_sms_id' => $id]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Customers who regularly answer: 2 or more of their last 3 settled calls reached (calls skipped before dialling,
     * e.g. bill already paid, don't count). header_id => true.
     */
    public static function regulars(int $companyId, array $headers): array
    {
        if (! $headers) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($headers)), 500) as $chunk) {
            VoiceReminderCall::where('company_id', $companyId)->whereIn('customer_header_id', $chunk)->whereIn('kind', ['reminder', 'line_off'])
                ->whereNull('alt_of')->whereNotNull('settled_at')->where('dedupe', 'not like', '%:test')
                ->where(fn ($q) => $q->where('reached', true)->orWhereNotIn('status', ['skipped', 'expired']))
                ->where('created_at', '>=', now()->subDays(120))
                ->orderByDesc('settled_at')->orderByDesc('id')->get(['customer_header_id', 'reached'])
                ->groupBy('customer_header_id')
                ->each(function ($calls, $h) use (&$out) {
                    if ($calls->take(3)->where('reached', true)->count() >= 2) {
                        $out[(int) $h] = true;
                    }
                });
        }

        return $out;
    }

    /**
     * For BillReminders: the customers (of $customers, whose expire date is $expire, SMS $n days before) who get no
     * reminder SMS now because a call does it: regular answerers the call reaches (a call is still to come on or
     * before that SMS day, or one for this expire date was made; if it got nobody, backup() sends the SMS).
     * header_id => true.
     */
    public static function coversSms(int $companyId, Collection $customers, Carbon $expire, int $n): array
    {
        $s = VoiceReminderSetting::where('company_id', $companyId)->first();
        if (! $s || ! $s->enabled || $s->test_mode || ! $s->reminder_on || ! $s->reminder_flow_id || ! $s->sms_saving || $customers->isEmpty()) {
            return [];
        }
        $coming = collect(BillReminders::days($s->reminder_days))->contains(fn ($d) => $d <= $n);
        $regular = static::regulars($companyId, $customers->pluck('header_id')->all());
        if (! $regular) {
            return [];
        }
        $optout = DB::table('voice_optouts')->where('company_id', $companyId)->pluck('number')->flip();
        $called = $coming ? collect() : VoiceReminderCall::where('company_id', $companyId)->where('kind', 'reminder')->whereNull('alt_of')
            ->where('dedupe', 'like', 'bill:%:'.$expire->toDateString().':%')->where('dedupe', 'not like', '%:test')
            ->whereIn('customer_header_id', array_keys($regular))->pluck('customer_header_id')->flip();
        $out = [];
        foreach ($customers as $c) {
            if (isset($regular[$c->header_id]) && (! $s->due_only || (float) $c->due > 0) && ! isset($optout[$c->mobile_normalized])
                && ($coming || isset($called[$c->header_id]))) {
                $out[(int) $c->header_id] = true;
            }
        }

        return $out;
    }

    /** For the settings page: who would be called on $day and the rough minutes and cost. */
    public static function preview(int $companyId, VoiceReminderSetting $s, Carbon $day): array
    {
        $day = $day->copy()->timezone(self::TZ)->startOfDay();
        $byDays = [];
        $headers = [];
        foreach (BillReminders::days($s->reminder_days) as $n) {
            $t = static::billTargets($companyId, $s, $day, $n);
            $byDays[$n] = $t->count();
            array_push($headers, ...$t->pluck('header_id')->all());
        }
        $optout = DB::table('voice_optouts')->where('company_id', $companyId)->count();
        $total = array_sum($byDays);
        $minutes = (int) ceil($total * 1.2 * 1.3);   // ~70 s a call, some tried twice

        return ['days' => $byDays, 'total' => $total, 'minutes' => $minutes, 'cost' => round($minutes * (float) $s->minute_rate),
            'off_now' => static::offLines($companyId)->count(), 'optouts' => $optout,
            'regulars' => $s->sms_saving ? count(static::regulars($companyId, $headers)) : 0];
    }
}
