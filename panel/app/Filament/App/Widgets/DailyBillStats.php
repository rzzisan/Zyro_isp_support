<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\DailyBills\Pages\ListDailyBills;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/** Totals of the বিল কালেকশন table as it is filtered right now. */
class DailyBillStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    #[On('daily-bills-changed')]
    public function refreshFromLive(): void {}

    protected function getTablePage(): string
    {
        return ListDailyBills::class;
    }

    /** count, received, discount, due of a (filtered) query. */
    public static function totals(Builder $query): array
    {
        // the table query selects columns (and a receipt subquery); aggregate over its filters only
        $r = $query->toBase()->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order'])->selectRaw('count(*) AS n, coalesce(sum(receive_amount), 0) AS paid,
            coalesce(sum(discount_amount), 0) AS discount, coalesce(sum(balance_due), 0) AS due')->first();

        return ['count' => (int) $r->n, 'paid' => (float) $r->paid, 'discount' => (float) $r->discount, 'due' => (float) $r->due];
    }

    public static function tk(float $v): string
    {
        return number_format($v, $v == floor($v) ? 0 : 2).' ৳';
    }

    protected function getStats(): array
    {
        $t = static::totals($this->getPageTableQuery());

        return [
            Stat::make('বিল', number_format($t['count']).'টি')->icon(Heroicon::OutlinedDocumentText),
            Stat::make('জমা', static::tk($t['paid']))->icon(Heroicon::OutlinedBanknotes)->color('success'),
            Stat::make('ডিসকাউন্ট', static::tk($t['discount']))->icon(Heroicon::OutlinedReceiptPercent)->color('warning'),
            Stat::make('বকেয়া', static::tk($t['due']))->icon(Heroicon::OutlinedExclamationCircle)->color('danger'),
        ];
    }
}
