<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\DeskCustomer;
use App\Models\DeskExtension;
use App\Models\Membership;
use App\Models\User;
use App\Services\Engine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * সময় বাড়ানো (billing phase 4a, plan 2.2 #11): an overdue customer's line stays on until a date, with a reason.
 * The latest active extension is copied into desk_customers.extended_until, which the turn-off list reads; the hourly
 * job (engine/desk_disable.py) ends it when the date passes or the customer has nothing due any more.
 * Nothing is written to the billing software. If the line is already off it can be turned on: on the MikroTik by
 * the Desk for its test accounts (line_control), otherwise through billing like the customer list's switch.
 */
class Extensions
{
    public static function today(): Carbon
    {
        return now('Asia/Dhaka')->startOfDay();
    }

    public static function canExtend(?User $user, Company $company): bool
    {
        return Menu::allows($user, $company, 'bill_extend');
    }

    public static function isOwner(?User $user, Company $company): bool
    {
        return $user?->roleIn($company) === 'owner';
    }

    public static function deskCustomer(BillingCustomer $b): ?DeskCustomer
    {
        return $b->header_id ? DeskCustomer::where('company_id', $b->company_id)->where('header_id', $b->header_id)->first() : null;
    }

    public static function active(int $companyId, int $customerRef): ?DeskExtension
    {
        return DeskExtension::where('company_id', $companyId)->where('customer_ref', $customerRef)->where('status', 'active')
            ->orderByDesc('id')->first();
    }

    /** Extensions given this (Bangladesh) month, not counting canceled ones. */
    public static function thisMonth(int $companyId, int $customerRef): int
    {
        return DeskExtension::where('company_id', $companyId)->where('customer_ref', $customerRef)
            ->where('given_on', '>=', static::today()->startOfMonth()->toDateString())
            ->whereNotIn('kind', ['import'])->where('status', '<>', 'canceled')->count();
    }

    /** What stops this member giving this date (null = fine). The owner only gets the hard checks. */
    public static function refusal(Company $company, DeskCustomer $c, Carbon $until, ?User $user): ?string
    {
        $today = static::today();
        if ($until->lte($today)) {
            return __('তারিখ আজকের পরের হতে হবে।');
        }
        if ($c->is_left || $c->status === 'Left' || $c->gone_at) {
            return __('Left কাস্টমারের সময় বাড়ানো যায় না।');
        }
        if (static::isOwner($user, $company)) {
            return null;
        }
        $s = BillingSetting::forCompany($company);
        $max = (int) ($s->extend_max_days ?? 10);
        $days = (int) $today->diffInDays($until);
        if ($max > 0 && $days > $max) {
            return __('একবারে সর্বোচ্চ :n দিন বাড়ানো যায় (বিলিং নিয়ম)। বেশি লাগলে মালিককে বলুন।', ['n' => $max]);
        }
        $per = (int) ($s->extend_per_month ?? 1);
        if ($per > 0 && static::thisMonth($company->id, $c->id) >= $per) {
            return __('এই কাস্টমারকে এই মাসে :n বার সময় দেওয়া হয়ে গেছে (বিলিং নিয়ম)। আবার লাগলে মালিককে বলুন।', ['n' => $per]);
        }

        return null;
    }

    /** Is the owner going past the limits with this date? (kept on the row) */
    private static function overLimit(Company $company, DeskCustomer $c, Carbon $until): bool
    {
        $s = BillingSetting::forCompany($company);
        $max = (int) ($s->extend_max_days ?? 10);
        $per = (int) ($s->extend_per_month ?? 1);

        return ($max > 0 && static::today()->diffInDays($until) > $max)
            || ($per > 0 && static::thisMonth($company->id, $c->id) >= $per);
    }

    public static function due(DeskCustomer $c): ?float
    {
        $b = DB::table('desk_customer_balances')->where('customer_ref', $c->id)->first();
        if (! $b) {
            return null;
        }

        return (float) ($b->has_history ? $b->balance : ($b->billing_due ?? $b->balance));
    }

    /**
     * Give (or replace) the extension. $enableLine: the line is off and should be turned on now.
     * Returns the new row; its line_action says what happened to an off line.
     */
    public static function give(Company $company, DeskCustomer $c, Carbon $until, string $reason, ?User $user,
        string $kind = 'manual', string $source = 'panel', bool $enableLine = false): DeskExtension
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('কারণ লিখতে হবে।'));
        }
        if ($why = static::refusal($company, $c, $until, $user)) {
            throw new RuntimeException($why);
        }
        $today = static::today();
        $over = static::isOwner($user, $company) && static::overLimit($company, $c, $until);
        $row = DB::transaction(function () use ($company, $c, $until, $reason, $user, $kind, $source, $today, $over) {
            DeskExtension::where('company_id', $company->id)->where('customer_ref', $c->id)->where('status', 'active')
                ->update(['status' => 'replaced', 'ended_at' => now(), 'ended_by' => $user?->id]);
            $row = DeskExtension::create(['company_id' => $company->id, 'customer_ref' => $c->id, 'customer_code' => $c->customer_code,
                'until' => $until->toDateString(), 'given_on' => $today->toDateString(), 'days' => (int) $today->diffInDays($until),
                'reason' => mb_substr($reason, 0, 1000), 'kind' => $kind, 'source' => $source, 'status' => 'active',
                'over_limit' => $over, 'due_at_start' => static::due($c), 'user_id' => $user?->id, 'created_at' => now()]);
            DeskCustomer::whereKey($c->id)->update(['extended_until' => $until->toDateString(), 'updated_at' => now()]);

            return $row;
        });
        Activity::log('billing.extend', ['extended_until' => [$c->extended_until, $until->toDateString()]], $row,
            __(':d পর্যন্ত সময়: :r', ['d' => $until->format('d M Y'), 'r' => $reason]), $c->customer_code, $company->id, $user?->id);
        if (static::lineOff($c)) {
            $row->line_action = $enableLine ? static::enable($company, $c, $user, $source, $reason) : 'left_off';
            $row->save();
        }

        return $row;
    }

    public static function cancel(DeskExtension $e, string $note, ?User $user): void
    {
        DB::transaction(function () use ($e, $note, $user) {
            $e->update(['status' => 'canceled', 'ended_at' => now(), 'end_note' => mb_substr(trim($note), 0, 500), 'ended_by' => $user?->id]);
            DeskCustomer::whereKey($e->customer_ref)->update(['extended_until' => null, 'updated_at' => now()]);
        });
        Activity::log('billing.extend_cancel', ['extended_until' => [$e->until?->toDateString(), null]], $e, $note,
            $e->customer_code, $e->company_id, $user?->id);
    }

    /** The line as the last copy of billing (or a Desk switch) shows it. */
    public static function lineOff(DeskCustomer $c): bool
    {
        $b = BillingCustomer::where('company_id', $c->company_id)->where('header_id', $c->header_id)->first();

        return $b ? (bool) $b->disabled : $c->line_state === 'off';
    }

    /** Turn an off line on: the Desk itself for its test accounts, otherwise through billing (needs line_switch). */
    private static function enable(Company $company, DeskCustomer $c, ?User $user, string $source, string $reason): string
    {
        $s = BillingSetting::forCompany($company);
        $codes = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $s->line_test_codes ?? []);
        $desk = ($s->line_control ?? 'off') === 'all'
            || (($s->line_control ?? 'off') === 'test' && array_intersect([mb_strtolower((string) $c->customer_code), mb_strtolower((string) $c->username)], $codes));
        try {
            if ($desk) {
                $r = Engine::deskLine($company->id, 'enable', ['header_id' => $c->header_id], 'extend', mb_substr($reason, 0, 400));

                return ($r['status'] ?? null) === 'done' ? 'enabled' : 'enable_failed';
            }
            if (! Menu::allows($user, $company, 'line_switch') || ! Membership::mayUseBilling($user, $company)) {
                return 'left_off';
            }
            Engine::switchLine($company->id, (int) $c->header_id, true);

            return 'enabled';
        } catch (RuntimeException $e) {
            report($e);

            return 'enable_failed';
        }
    }
}
