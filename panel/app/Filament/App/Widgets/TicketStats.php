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

    protected function getHeading(): ?string
    {
        return __('বিলিংয়ের টিকিট');
    }

    protected function getDescription(): ?string
    {
        return __('বিলিং সফটওয়্যার থেকে প্রতি ৫ মিনিটে আপডেট হয়');
    }

    public static function minutes(?int $m): string
    {
        if ($m === null) {
            return '—';
        }

        return $m >= 60 ? __(':h ঘণ্টা :m মি.', ['h' => intdiv($m, 60), 'm' => $m % 60]) : __(':n মিনিট', ['n' => $m]);
    }

    protected function getStats(): array
    {
        $t = new TicketInsights(Filament::getTenant()->getKey());
        $today = Insights::todayStartUtc();
        $week = $today->subDays(6);
        $daily = $t->daily(7);
        $overdue = $t->overdue(24);

        return [
            Stat::make(__('এখন খোলা'), $t->openCount())
                ->description(__(':pendingটা অপেক্ষমাণ, :workingটায় কাজ চলছে', ['pending' => $t->openCount('pending'), 'working' => $t->openCount('processing')]))
                ->icon(Heroicon::OutlinedTicket)->url(TicketResource::getUrl('index')),
            Stat::make(__('২৪ ঘণ্টার বেশি খোলা'), $overdue)
                ->description($overdue ? __('দ্রুত দেখা দরকার') : __('কোনোটা পুরনো না'))
                ->descriptionColor($overdue ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedExclamationTriangle),
            Stat::make(__('আজ খোলা হয়েছে'), $t->openedSince($today))
                ->chart($daily['opened'])->chartColor('warning')
                ->icon(Heroicon::OutlinedPlusCircle),
            Stat::make(__('আজ সমাধান'), $t->solvedSince($today))
                ->chart($daily['solved'])->chartColor('success')
                ->icon(Heroicon::OutlinedCheckCircle),
            Stat::make(__('গড় সমাধানের সময়'), static::minutes($t->avgSolveMinutes($week)))
                ->description(__('গত ৭ দিনে সমাধান হওয়া টিকিট'))
                ->icon(Heroicon::OutlinedClock),
            Stat::make(__('৭ দিনে সমাধান'), $t->solvedSince($week))
                ->description(__('খোলা হয়েছে :nটা', ['n' => $t->openedSince($week)]))
                ->icon(Heroicon::OutlinedWrenchScrewdriver),
        ];
    }
}
