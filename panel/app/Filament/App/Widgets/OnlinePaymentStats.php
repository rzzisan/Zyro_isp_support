<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\OnlinePayments\Pages\ListOnlinePayments;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/** Totals of the অনলাইন পেমেন্ট table as it is filtered right now. */
class OnlinePaymentStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '60s';

    protected function getTablePage(): string
    {
        return ListOnlinePayments::class;
    }

    /** count and amount per source, and the unsettled ones, of a (filtered) query. */
    public static function totals(Builder $query): array
    {
        $r = $query->toBase()->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order'])->selectRaw(
            "count(*) AS n, coalesce(sum(amount), 0) AS amount,
             count(*) FILTER (WHERE source = 'webhook') AS wn, coalesce(sum(amount) FILTER (WHERE source = 'webhook'), 0) AS wa,
             count(*) FILTER (WHERE source = 'paybill') AS pn, coalesce(sum(amount) FILTER (WHERE source = 'paybill'), 0) AS pa,
             count(*) FILTER (WHERE status_id = 1) AS rn, coalesce(sum(amount) FILTER (WHERE status_id = 1), 0) AS ra")->first();

        return ['count' => (int) $r->n, 'amount' => (float) $r->amount, 'webhook' => [(int) $r->wn, (float) $r->wa],
            'paybill' => [(int) $r->pn, (float) $r->pa], 'requested' => [(int) $r->rn, (float) $r->ra]];
    }

    protected function getStats(): array
    {
        $t = static::totals($this->getPageTableQuery());
        $tk = fn (float $v) => DailyBillStats::tk($v);
        $n = fn (int $v) => __(':nটি', ['n' => number_format($v)]);

        return [
            Stat::make(__('মোট'), $tk($t['amount']))->description($n($t['count']))->icon(Heroicon::OutlinedBanknotes)->color('success'),
            Stat::make('Webhook', $tk($t['webhook'][1]))->description($n($t['webhook'][0]))->icon(Heroicon::OutlinedBolt),
            Stat::make('PayBill', $tk($t['paybill'][1]))->description($n($t['paybill'][0]))->icon(Heroicon::OutlinedDevicePhoneMobile),
            Stat::make(__('সেটেল হয়নি'), $n($t['requested'][0]))->description($tk($t['requested'][1]))
                ->icon(Heroicon::OutlinedExclamationTriangle)->color($t['requested'][0] ? 'danger' : 'gray'),
        ];
    }
}
