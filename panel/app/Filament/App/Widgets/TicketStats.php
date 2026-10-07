<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Tickets\TicketResource;
use App\Services\Insights;
use App\Services\TicketInsights;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Billing tickets at a glance. */
class TicketStats extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'বিলিংয়ের টিকিট';

    protected ?string $description = 'বিলিং সফটওয়্যার থেকে প্রতি ৫ মিনিটে আপডেট হয়';

    public static function minutes(?int $m): string
    {
        if ($m === null) {
            return '—';
        }

        return $m >= 60 ? intdiv($m, 60).' ঘণ্টা '.($m % 60).' মি.' : "{$m} মিনিট";
    }

    protected function getStats(): array
    {
        $t = new TicketInsights(Filament::getTenant()->getKey());
        $today = Insights::todayStartUtc();
        $week = $today->subDays(6);
        $daily = $t->daily(7);
        $overdue = $t->overdue(24);

        return [
            Stat::make('এখন খোলা', $t->openCount())
                ->description($t->openCount('pending').'টা অপেক্ষমাণ, '.$t->openCount('processing').'টায় কাজ চলছে')
                ->icon(Heroicon::OutlinedTicket)->url(TicketResource::getUrl('index')),
            Stat::make('২৪ ঘণ্টার বেশি খোলা', $overdue)
                ->description($overdue ? 'দ্রুত দেখা দরকার' : 'কোনোটা পুরনো না')
                ->descriptionColor($overdue ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedExclamationTriangle),
            Stat::make('আজ খোলা হয়েছে', $t->openedSince($today))
                ->chart($daily['opened'])->chartColor('warning')
                ->icon(Heroicon::OutlinedPlusCircle),
            Stat::make('আজ সমাধান', $t->solvedSince($today))
                ->chart($daily['solved'])->chartColor('success')
                ->icon(Heroicon::OutlinedCheckCircle),
            Stat::make('গড় সমাধানের সময়', static::minutes($t->avgSolveMinutes($week)))
                ->description('গত ৭ দিনে সমাধান হওয়া টিকিট')
                ->icon(Heroicon::OutlinedClock),
            Stat::make('৭ দিনে সমাধান', $t->solvedSince($week))
                ->description('খোলা হয়েছে '.$t->openedSince($week).'টা')
                ->icon(Heroicon::OutlinedWrenchScrewdriver),
        ];
    }
}
