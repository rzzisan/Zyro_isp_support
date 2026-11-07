<?php

namespace App\Support;

use App\Models\Company;
use App\Models\DeskCustomer;
use App\Models\ReceiptSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * One customer's statement from the Desk's own ledger (billing phase 4c, plan 2.3): the balance on the first day, every
 * bill, payment, discount, waive and correction in the dates with a running balance, and the balance at the end.
 */
class Statement
{
    public const KINDS = [
        'opening' => 'আগের বকেয়া', 'bill' => 'মাসিক বিল', 'payment' => 'পেমেন্ট', 'discount' => 'ডিসকাউন্ট',
        'adjustment' => 'সংশোধন', 'waive' => 'বিল মাফ', 'transfer' => 'ট্রান্সফার',
    ];

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, opening: float, rows: array<int, array>, closing: float} */
    public static function data(DeskCustomer $c, ?string $from = null, ?string $to = null): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $to = rescue(fn () => $to ? CarbonImmutable::parse($to, 'Asia/Dhaka')->startOfDay() : $today, $today, false);
        $from = rescue(fn () => $from ? CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay() : null, null, false)
            ?? $to->subMonthsNoOverflow(5)->startOfMonth();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $base = fn () => DB::table('desk_ledger_entries as l')->where('l.company_id', $c->company_id)->where('l.customer_ref', $c->id);
        $opening = (float) $base()->where('l.entry_date', '<', $from->toDateString())->sum('l.amount');
        $bal = $opening;
        $rows = [];
        $entries = $base()->whereBetween('l.entry_date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('desk_bills as b', 'b.id', '=', 'l.bill_ref')
            ->leftJoin('desk_payments as p', 'p.id', '=', 'l.payment_ref')
            ->orderBy('l.entry_date')->orderByRaw("CASE l.kind WHEN 'opening' THEN 0 WHEN 'bill' THEN 1 ELSE 2 END")->orderBy('l.id')
            ->get(['l.entry_date', 'l.kind', 'l.amount', 'l.note', 'b.period_start', 'p.method', 'p.trx_id', 'p.receipt_no', 'p.received_by']);
        foreach ($entries as $e) {
            $amount = (float) $e->amount;
            $bal = round($bal + $amount, 2);
            $detail = match ($e->kind) {
                'bill', 'waive' => $e->period_start ? CarbonImmutable::parse($e->period_start)->format('M Y') : null,
                'payment' => trim(implode(' · ', array_filter([$e->method, $e->trx_id, $e->receipt_no ? '#'.$e->receipt_no : null, $e->received_by]))),
                default => null,
            };
            $rows[] = ['date' => CarbonImmutable::parse($e->entry_date), 'kind' => $e->kind,
                'detail' => trim(implode(' · ', array_filter([$detail, $e->kind === 'payment' ? null : $e->note]))),
                'debit' => $amount > 0 ? $amount : 0.0, 'credit' => $amount < 0 ? -$amount : 0.0, 'balance' => $bal];
        }

        return ['from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows, 'closing' => $bal];
    }

    public static function view(Company $company, string $code, ?string $from, ?string $to, bool $autoPrint = false): View
    {
        $c = DeskCustomer::where('company_id', $company->id)->where('customer_code', $code)->firstOrFail();

        return view('print.statement', ['company' => $company, 'c' => $c, 'settings' => ReceiptSetting::forCompany($company),
            'autoPrint' => $autoPrint, ...static::data($c, $from, $to)]);
    }
}
