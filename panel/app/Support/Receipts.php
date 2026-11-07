<?php

namespace App\Support;

use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\BillingDailyBill;
use App\Models\Company;
use App\Models\DeskPayment;
use App\Models\Membership;
use App\Models\ReceiptSetting;
use App\Models\User;
use App\Services\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * The thermal money receipt (print.receipt) of a Desk collection or of a synced billing bill. The panel prints it in
 * the browser; the Android app renders the same page to a bitmap for its Bluetooth printer.
 */
class Receipts
{
    /** ?w=58|80, else the company's paper width. */
    private static function width(ReceiptSetting $settings, mixed $w): int
    {
        return in_array((int) $w, [58, 80], true) ? (int) $w : ($settings->paper_width ?: 80);
    }

    /** A bill collected from the Desk. Billing's own receipt number is fetched (and kept) once the bill is approved there. */
    public static function collection(Company $company, int $collection, mixed $w = null, bool $autoPrint = true): View
    {
        $c = BillCollection::where('company_id', $company->id)->where('result', 'saved')->findOrFail($collection);
        // billing only has a receipt number after approval; asking before that just makes printing slower
        $approved = $c->approved_at || ($c->daily_bill_id && BillingDailyBill::where('company_id', $company->id)
            ->where('daily_bill_id', $c->daily_bill_id)->where('is_approved', true)->exists());
        if (! $c->money_receipt_no && $approved) {
            try {
                $c->money_receipt_no = Engine::receiptNo($company->id, $c->id);
            } catch (\Throwable $e) {
                report($e); // billing unreachable: print with the Desk number
            }
        }
        $settings = ReceiptSetting::forCompany($company);

        return view('print.receipt', ['company' => $company, 'c' => $c, 'settings' => $settings, 'width' => static::width($settings, $w),
            'customer' => BillingCustomer::where('company_id', $company->id)->where('header_id', $c->customer_header_id)->first(),
            'receiptNo' => $c->money_receipt_no ?: 'D-'.$c->id, 'autoPrint' => $autoPrint]);
    }

    /**
     * Any bill in billing's Daily Bill Collection (synced) that the member may see on the বিল কালেকশন page.
     * Billing's receipt number once approved, else "B-{DailyBillId}".
     */
    public static function dailyBill(Company $company, User $user, int $dailyBill, mixed $w = null, bool $autoPrint = true): View
    {
        $m = Membership::where('company_id', $company->id)->where('user_id', $user->id)->first();
        $d = BillingDailyBill::where('company_id', $company->id)->where('daily_bill_id', $dailyBill)
            ->where('is_deleted', false)->visibleTo($m)->firstOrFail();
        if (! $d->money_receipt_no && $d->is_approved) {
            try {
                $d->money_receipt_no = Engine::dailyReceiptNo($company->id, $d->daily_bill_id);
            } catch (\Throwable $e) {
                report($e); // billing unreachable: print with the bill's own number
            }
        }
        // the receipt layout reads a collection; fill one (unsaved) from the synced bill. Billing keeps an advance as a
        // negative balance, so the bill was paid + discount + balance and the rest shows under Advance
        $c = new BillCollection(['customer_id' => $d->customer_id, 'customer_name' => $d->customer_name,
            'username' => $d->username, 'payable' => max(0, round((float) $d->receive_amount + (float) $d->discount_amount + (float) $d->balance_due, 2)),
            'paid' => $d->receive_amount, 'discount' => $d->discount_amount, 'payment_method' => $d->payment_method,
            'received_by' => $d->received_by, 'money_receipt_no' => $d->money_receipt_no]);
        $c->created_at = $d->collected_at;
        $c->mobile = $d->mobile;
        $settings = ReceiptSetting::forCompany($company);

        return view('print.receipt', ['company' => $company, 'c' => $c, 'settings' => $settings, 'width' => static::width($settings, $w),
            'customer' => $d->customer_header_id ? BillingCustomer::where('company_id', $company->id)->where('header_id', $d->customer_header_id)->first() : null,
            'receiptNo' => $d->money_receipt_no ?: 'B-'.$d->daily_bill_id, 'autoPrint' => $autoPrint]);
    }

    /**
     * A payment in the Desk's own ledger (billing phase 4c), same layout. Payable is the balance before this payment:
     * the ledger up to the payment's day, with this payment's own rows added back once it is approved. Receipt number
     * billing's (copied payments) or the Desk's, else "DP-{id}".
     */
    public static function deskPayment(Company $company, int $payment, mixed $w = null, bool $autoPrint = true): View
    {
        $p = DeskPayment::with('customer')->where('company_id', $company->id)->whereIn('status', ['approved', 'pending'])->findOrFail($payment);
        $upTo = (float) DB::table('desk_ledger_entries')->where('company_id', $company->id)->where('customer_ref', $p->customer_ref)
            ->where('entry_date', '<=', $p->paid_on->toDateString())->sum('amount');
        $paid = (float) $p->amount;
        $disc = (float) $p->discount;
        $cu = $p->customer;
        $c = new BillCollection(['customer_id' => $cu?->customer_code, 'customer_name' => $cu?->name, 'username' => $cu?->username,
            'payable' => max(0, round($upTo + ($p->status === 'approved' ? $paid + $disc : 0), 2)),
            'paid' => $paid, 'discount' => $disc, 'payment_method' => trim(($p->method ?? '').($p->trx_id ? ' '.$p->trx_id : '')),
            'received_by' => $p->received_by, 'money_receipt_no' => $p->receipt_no]);
        $c->created_at = $p->paid_at ?? $p->created_at ?? $p->paid_on;
        $c->mobile = $cu?->mobile;
        $settings = ReceiptSetting::forCompany($company);

        return view('print.receipt', ['company' => $company, 'c' => $c, 'settings' => $settings, 'width' => static::width($settings, $w),
            'customer' => $cu ? BillingCustomer::where('company_id', $company->id)->where('header_id', $cu->header_id)->first() : null,
            'receiptNo' => $p->receipt_no ?: 'DP-'.$p->id, 'autoPrint' => $autoPrint]);
    }
}
