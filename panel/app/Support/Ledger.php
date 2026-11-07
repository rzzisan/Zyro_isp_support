<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\DeskBill;
use App\Models\DeskCustomer;
use App\Models\DeskPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The Desk writes its own ledger (billing phase 4b, plan 2.2 #3, #4). A row is never changed or removed: a canceled
 * or transferred payment and an edited bill get a reverse/adjustment ledger row, and the customer's balance moves in
 * the same transaction.
 *
 *  - A bill taken with the বিল নিন form (still saved in billing too, as now) becomes a Desk payment at once, with
 *    billing's Daily Bill id; the nightly copy then leaves billing's receipt of the same money out of the ledger.
 *  - Cash payment cancel (payment_delete), payment transfer to another ID (payment_transfer), bill edit/void
 *    (bill_edit): reason required, all in the activity log.
 *
 * Guard billing_settings.ledger_control: 'off' writes nothing; 'test' only for the customers in ledger_test_codes,
 * and before the cutover corrections only touch the Desk's own payments (billing's copied rows are billing's to fix);
 * 'all' only once the Desk is the main billing (billing_mode desk).
 */
class Ledger
{
    public const CONTROL = [
        'off' => 'বন্ধ (Desk নিজের খাতায় কিছু লেখে না)',
        'test' => 'শুধু পরীক্ষার অ্যাকাউন্টে',
        'all' => 'সবার (শুধু হস্তান্তরের পর)',
    ];

    private static function settings(int $companyId): BillingSetting
    {
        return BillingSetting::forCompany(Company::findOrFail($companyId));
    }

    public static function deskMode(int $companyId): bool
    {
        return (static::settings($companyId)->billing_mode ?? 'ispdigital') === 'desk';
    }

    /** Why the Desk may not write this customer's ledger now (null = it may). */
    public static function refusal(DeskCustomer $c): ?string
    {
        $s = static::settings($c->company_id);
        $mode = $s->ledger_control ?? 'off';
        if ($mode === 'off') {
            return __('বিলিং নিয়মে "Desk-এর নিজের খাতায় লেখা" বন্ধ আছে।');
        }
        if ($mode === 'all') {
            return ($s->billing_mode ?? 'ispdigital') === 'desk' ? null : __('হস্তান্তরের আগে শুধু পরীক্ষার অ্যাকাউন্টে চালানো যায়।');
        }
        $codes = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $s->ledger_test_codes ?? []);
        if (! array_intersect([mb_strtolower((string) $c->customer_code), mb_strtolower((string) $c->username)], $codes)) {
            return __('এই কাস্টমার পরীক্ষার তালিকায় নেই (বিলিং নিয়ম → খাতার পরীক্ষার অ্যাকাউন্ট)।');
        }

        return null;
    }

    /** Before the cutover only the Desk's own payments are corrected here; billing's copies are fixed in billing. */
    private static function paymentRefusal(DeskPayment $p): ?string
    {
        if (! $p->customer) {
            return __('কাস্টমার পাওয়া যায়নি।');
        }
        if ($why = static::refusal($p->customer)) {
            return $why;
        }
        if ($p->source !== 'desk' && ! static::deskMode($p->company_id)) {
            return __('এই পেমেন্ট বিলিং থেকে কপি করা। হস্তান্তরের আগে এটা বিলিং-এই ঠিক করুন।');
        }

        return null;
    }

    public static function canCancel(DeskPayment $p, ?User $user): bool
    {
        return ! $p->is_online && in_array($p->status, ['approved', 'pending'], true) && static::paymentRefusal($p) === null
            && Menu::allows($user, Company::find($p->company_id), 'payment_delete');
    }

    public static function canTransfer(DeskPayment $p, ?User $user): bool
    {
        return $p->status === 'approved' && static::paymentRefusal($p) === null
            && Menu::allows($user, Company::find($p->company_id), 'payment_transfer');
    }

    public static function canEditBill(DeskBill $b, ?User $user): bool
    {
        return $b->status !== 'void' && $b->customer && static::refusal($b->customer) === null
            && Menu::allows($user, Company::find($b->company_id), 'bill_edit');
    }

    /** One ledger row and the balance with it (call inside a transaction). */
    private static function entry(DeskCustomer $c, string $kind, float $amount, string $ref, ?string $note, ?int $userId,
        ?int $paymentRef = null, ?int $billRef = null): void
    {
        if (abs($amount) < 0.005) {
            return;
        }
        $today = Extensions::today()->toDateString();
        DB::table('desk_ledger_entries')->insert(['company_id' => $c->company_id, 'customer_ref' => $c->id, 'entry_date' => $today,
            'kind' => $kind, 'amount' => round($amount, 2), 'payment_ref' => $paymentRef, 'bill_ref' => $billRef, 'source' => 'desk',
            'source_ref' => $ref, 'note' => $note ? mb_substr($note, 0, 1000) : null, 'user_id' => $userId, 'created_at' => now()]);
        $set = ['updated_at' => now()] + ($kind === 'payment' && $amount < 0 ? ['last_payment_on' => $today] : []);
        $n = DB::table('desk_customer_balances')->where('customer_ref', $c->id)
            ->update(['balance' => DB::raw('balance + '.round($amount, 2))] + $set);
        if (! $n) {
            DB::table('desk_customer_balances')->insert(['customer_ref' => $c->id, 'company_id' => $c->company_id,
                'balance' => round($amount, 2), 'has_history' => false] + $set);
        }
    }

    /** The payment's money into the ledger (- lowers the due); $sign -1 reverses it. */
    private static function money(DeskPayment $p, DeskCustomer $c, string $kind, string $prefix, int $sign, ?string $note, ?int $userId): void
    {
        static::entry($c, $kind, -$sign * (float) $p->amount, $prefix.':'.$p->id, $note, $userId, $p->id);
        static::entry($c, $kind === 'payment' ? 'discount' : $kind, -$sign * (float) $p->discount, $prefix.'-disc:'.$p->id, $note, $userId, $p->id);
    }

    /**
     * After the বিল নিন form saved a bill in billing: the same money as a Desk payment. Never throws (the bill is
     * already in billing); returns the row, or null when the guard says no.
     *
     * @param  array{paid: float, discount: float, method?: ?string, received_by?: ?string, trx_no?: ?string, remarks?: ?string}  $data
     */
    public static function recordCollection(BillingCustomer $b, array $data, array $res, ?User $user, string $source = 'panel'): ?DeskPayment
    {
        try {
            $c = Extensions::deskCustomer($b);
            if (! $c || static::refusal($c) !== null) {
                return null;
            }
            $auto = (bool) (static::settings($c->company_id)->desk_auto_approve ?? true);

            return DB::transaction(function () use ($c, $data, $res, $user, $source, $auto) {
                $p = DeskPayment::create(['company_id' => $c->company_id, 'customer_ref' => $c->id,
                    'paid_on' => Extensions::today()->toDateString(), 'paid_at' => now(), 'amount' => round((float) $data['paid'], 2),
                    'discount' => round((float) ($data['discount'] ?? 0), 2), 'method' => $data['method'] ?? null,
                    'trx_id' => $data['trx_no'] ?? null, 'received_by' => $data['received_by'] ?? null, 'received_user_id' => $user?->id,
                    'status' => $auto ? 'approved' : 'pending', 'is_online' => false, 'source' => 'desk',
                    'daily_bill_id' => $res['daily_bill_id'] ?? null, 'collection_id' => $res['collection_id'] ?? null,
                    'note' => $data['remarks'] ?? null, 'user_id' => $user?->id]);
                if ($auto) {
                    static::money($p, $c, 'payment', 'dpay', 1, null, $user?->id);
                }
                Activity::log('billing.payment', ['amount' => [null, (float) $p->amount], 'discount' => [null, (float) $p->discount]], $p,
                    __('Desk-এর খাতায় পেমেন্ট (:s)', ['s' => $source]), $c->customer_code, $c->company_id, $user?->id);

                return $p;
            });
        } catch (Throwable $e) {
            Log::warning('desk ledger payment failed: '.$e->getMessage(), ['header_id' => $b->header_id]);

            return null;
        }
    }

    /** Cash/hand payment taken by mistake: canceled (never deleted) with a reverse ledger row. */
    public static function cancel(DeskPayment $p, string $reason, ?User $user): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('কারণ লিখতে হবে।'));
        }
        if ($p->is_online) {
            throw new RuntimeException(__('অনলাইন পেমেন্ট মোছা যায় না, শুধু অন্য ID-তে ট্রান্সফার করা যায়।'));
        }
        if (! in_array($p->status, ['approved', 'pending'], true)) {
            throw new RuntimeException(__('এই পেমেন্ট আগেই বাতিল বা ট্রান্সফার হয়েছে।'));
        }
        if (! Menu::allows($user, Company::find($p->company_id), 'payment_delete')) {
            throw new RuntimeException(__('"ক্যাশ পেমেন্ট মোছা"-র অনুমতি নেই।'));
        }
        if ($why = static::paymentRefusal($p)) {
            throw new RuntimeException($why);
        }
        DB::transaction(function () use ($p, $reason, $user) {
            $was = $p->status;
            $p->update(['status' => 'canceled', 'cancel_reason' => mb_substr($reason, 0, 1000), 'canceled_by' => $user?->id, 'canceled_at' => now()]);
            if ($was === 'approved') {
                static::money($p, $p->customer, 'adjustment', 'dpay-cancel', -1, __('পেমেন্ট বাতিল: :r', ['r' => $reason]), $user?->id);
            }
        });
        Activity::log('billing.payment_cancel', ['status' => ['approved', 'canceled']], $p, $reason, $p->customer->customer_code, $p->company_id, $user?->id);
    }

    /** Money paid to the wrong ID: out of the old customer's ledger, into the new one's; the TrxID stays. */
    public static function transfer(DeskPayment $p, DeskCustomer $to, string $reason, ?User $user): DeskPayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('কারণ লিখতে হবে।'));
        }
        if ($p->status !== 'approved') {
            throw new RuntimeException(__('শুধু জমা হওয়া পেমেন্ট ট্রান্সফার করা যায়।'));
        }
        if ($to->id === $p->customer_ref || $to->company_id !== $p->company_id) {
            throw new RuntimeException(__('অন্য একজন কাস্টমার বাছুন।'));
        }
        if (! Menu::allows($user, Company::find($p->company_id), 'payment_transfer')) {
            throw new RuntimeException(__('"পেমেন্ট ট্রান্সফার"-এর অনুমতি নেই।'));
        }
        if (($why = static::paymentRefusal($p)) || ($why = static::refusal($to))) {
            throw new RuntimeException($why);
        }
        $from = $p->customer;
        $new = DB::transaction(function () use ($p, $to, $from, $reason, $user) {
            $p->update(['status' => 'transferred', 'transfer_to_ref' => $to->id]);
            static::money($p, $from, 'transfer', 'dpay-out', -1, __('ট্রান্সফার: ID :a থেকে ID :b-এ (:r)', ['a' => $from->customer_code, 'b' => $to->customer_code, 'r' => $reason]), $user?->id);
            $new = DeskPayment::create(['company_id' => $p->company_id, 'customer_ref' => $to->id, 'paid_on' => Extensions::today()->toDateString(),
                'paid_at' => $p->paid_at ?? now(), 'amount' => $p->amount, 'discount' => $p->discount, 'method' => $p->method, 'gateway' => $p->gateway,
                'trx_id' => $p->trx_id, 'receipt_no' => $p->receipt_no, 'received_by' => $p->received_by, 'status' => 'approved',
                'is_online' => $p->is_online, 'source' => 'desk', 'transfer_of' => $p->id,
                'note' => __('ট্রান্সফার: ID :a থেকে (:r)', ['a' => $from->customer_code, 'r' => $reason]), 'user_id' => $user?->id]);
            static::money($new, $to, 'transfer', 'dpay-in', 1, __('ট্রান্সফার: ID :a থেকে', ['a' => $from->customer_code]), $user?->id);

            return $new;
        });
        Activity::log('billing.payment_transfer', ['customer' => [$from->customer_code, $to->customer_code]], $p, $reason,
            $from->customer_code, $p->company_id, $user?->id);

        return $new;
    }

    /** A bill's amount changed (or the bill voided): kept in desk_bill_versions, the difference into the ledger. */
    public static function editBill(DeskBill $b, ?float $amount, string $reason, ?User $user): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('কারণ লিখতে হবে।'));
        }
        if ($b->status === 'void') {
            throw new RuntimeException(__('বিলটা আগেই বাতিল।'));
        }
        if ($amount !== null && $amount < 0) {
            throw new RuntimeException(__('টাকা মাইনাস হতে পারে না।'));
        }
        if (! Menu::allows($user, Company::find($b->company_id), 'bill_edit')) {
            throw new RuntimeException(__('"বিল সংশোধন"-এর অনুমতি নেই।'));
        }
        if (! $b->customer || ($why = static::refusal($b->customer))) {
            throw new RuntimeException($why ?? __('কাস্টমার পাওয়া যায়নি।'));
        }
        $old = (float) $b->amount;
        $new = $amount === null ? 0.0 : round($amount, 2);
        if (abs($new - $old) < 0.005 && $amount !== null) {
            throw new RuntimeException(__('টাকা বদলায়নি।'));
        }
        DB::transaction(function () use ($b, $old, $new, $amount, $reason, $user) {
            $v = DB::table('desk_bill_versions')->insertGetId(['company_id' => $b->company_id, 'bill_ref' => $b->id,
                'period_start' => $b->period_start->toDateString(), 'old_amount' => $old, 'new_amount' => $new,
                'reason' => mb_substr($reason, 0, 1000), 'user_id' => $user?->id, 'source' => 'desk', 'created_at' => now()]);
            $b->update(['amount' => $new, 'status' => $amount === null ? 'void' : $b->status, 'note' => mb_substr($reason, 0, 1000), 'user_id' => $user?->id]);
            static::entry($b->customer, 'adjustment', $new - $old, 'billedit:'.$v,
                __(':m-এর বিল :a → :b (:r)', ['m' => $b->period_start->format('M Y'), 'a' => $old, 'b' => $new, 'r' => $reason]), $user?->id, null, $b->id);
        });
        Activity::log($amount === null ? 'billing.bill_void' : 'billing.bill_edit', ['amount' => [$old, $new]], $b, $reason,
            $b->customer->customer_code, $b->company_id, $user?->id);
    }
}
