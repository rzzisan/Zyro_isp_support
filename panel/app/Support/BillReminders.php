<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bill reminder SMS from the Desk (instead of billing's due SMS): N days before a customer's expire date, at the set
 * hour (Bangladesh time), once per customer + expire date + N (sms_reminders). The expire date is billing's
 * BillingLastDate, a day of the month (billing_customers.bill_day, refreshed by the customer sync): the next such
 * day from today (the month's last day when the month is shorter). Run hourly by `php artisan sms:bill-reminders`.
 */
class BillReminders
{
    public const TZ = 'Asia/Dhaka';

    /** "3, 1,0" -> [3, 1, 0] (0-31, unique, largest first). */
    public static function days(?string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\d]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $d) {
            if ((int) $d <= 31) {
                $out[(int) $d] = (int) $d;
            }
        }
        krsort($out);

        return array_values($out);
    }

    /** The customer's next expire date on or after $day (bill_day in this month, else next month). */
    public static function expireDate(int $billDay, Carbon $day): Carbon
    {
        $d = $day->copy()->startOfDay();
        $this_ = $d->copy()->day(min($billDay, $d->daysInMonth));
        if ($this_->gte($d)) {
            return $this_;
        }
        $next = $d->copy()->startOfMonth()->addMonthNoOverflow();

        return $next->day(min($billDay, $next->daysInMonth));
    }

    /** Customers whose expire date is $daysBefore days after $day (not Left, line on, with a due when asked). */
    public static function targets(int $companyId, SmsSetting $s, Carbon $day, int $daysBefore): Collection
    {
        $expire = $day->copy()->startOfDay()->addDays($daysBefore);

        return BillingCustomer::where('company_id', $companyId)->whereNull('gone_at')->where('is_left', false)
            ->where('disabled', false)->whereNotNull('bill_day')->where('bill_day', '>', 0)
            ->when($s->reminder_due_only, fn ($q) => $q->where('due', '>', 0))
            ->orderBy('customer_id')
            ->get(['header_id', 'customer_id', 'name', 'mobile', 'mobile_normalized', 'username', 'package', 'monthly_bill', 'due', 'zone', 'bill_day'])
            ->filter(fn ($c) => static::expireDate((int) $c->bill_day, $day)->isSameDay($expire))
            ->values();
    }

    /** For the settings page: [days before => how many would get it on $day] (already sent ones not counted). */
    public static function preview(int $companyId, SmsSetting $s, Carbon $day): array
    {
        $out = [];
        foreach (static::days($s->reminder_days) as $n) {
            $t = static::targets($companyId, $s, $day, $n);
            $sent = DB::table('sms_reminders')->where('company_id', $companyId)->where('days_before', $n)
                ->where('expire_date', $day->copy()->addDays($n)->toDateString())->whereIn('customer_header_id', $t->pluck('header_id'))->count();
            $out[$n] = $t->count() - $sent;
        }

        return $out;
    }

    /**
     * Queue the reminder SMS for $customers (expire date $expire, $n days before) and remember them in sms_reminders.
     * Returns how many went ($count) or the one message's id (a single backup SMS from VoiceReminders).
     */
    public static function send(int $companyId, SmsSetting $s, Collection $customers, Carbon $expire, int $n, ?string $body = null, bool $count = false): ?int
    {
        $body ??= (string) SmsTemplate::whereKey($s->reminder_template_id)->value('body');
        [$byMobile] = Sms::byMobile($customers);
        if (! $byMobile || $body === '') {
            return null;
        }
        $campaign = Sms::queue($companyId, null, 'reminder', $body, $byMobile, [], ['days_before' => $n],
            ['expire_date' => $expire->format('d/m/Y'), 'days_left' => (string) $n]);
        $ids = $campaign->messages()->pluck('id', 'customer_header_id');
        DB::table('sms_reminders')->insertOrIgnore($customers->map(fn ($c) => ['company_id' => $companyId,
            'customer_header_id' => $c->header_id, 'expire_date' => $expire->toDateString(), 'days_before' => $n,
            'message_id' => $ids[$c->header_id] ?? null, 'created_at' => now()])->values()->all());

        return $count ? $campaign->total : ($ids->first() ?: null);
    }

    /** Queue today's reminders for one company; returns [days before => SMS queued]. Safe to run again. */
    public static function run(int $companyId, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->timezone(self::TZ);
        $s = SmsSetting::forCompany($companyId);
        if (! $s->eventOn('bill_reminder') || $now->hour < (int) $s->reminder_hour) {
            return [];
        }
        $body = (string) SmsTemplate::whereKey($s->reminder_template_id)->value('body');
        $today = $now->copy()->startOfDay();
        $out = [];
        // one runner per company at a time (hourly timer + a manual run)
        if (! DB::selectOne('SELECT pg_try_advisory_lock(?, ?) AS ok', [7301, $companyId])->ok) {
            return [];
        }
        try {
            foreach (static::days($s->reminder_days) as $n) {
                $expire = $today->copy()->addDays($n);
                $done = DB::table('sms_reminders')->where('company_id', $companyId)->where('days_before', $n)
                    ->where('expire_date', $expire->toDateString())->pluck('customer_header_id')->flip();
                $todo = static::targets($companyId, $s, $today, $n)->reject(fn ($c) => isset($done[$c->header_id]));
                // regular answerers of the voice reminder call: the call tells them (the SMS only if it gets nobody)
                $byCall = VoiceReminders::coversSms($companyId, $todo, $expire, $n);
                $todo = $todo->reject(fn ($c) => isset($byCall[$c->header_id]));
                if ($sent = static::send($companyId, $s, $todo, $expire, $n, $body, true)) {
                    $out[$n] = $sent;
                }
            }
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?, ?)', [7301, $companyId]);
        }

        return $out;
    }
}
