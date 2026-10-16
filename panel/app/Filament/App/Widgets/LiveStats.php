<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Services\Insights;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Today at a glance (Bangladesh time), refreshed every 10 seconds. */
class LiveStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '10s';

    protected function getHeading(): ?string
    {
        return __('আজকের অবস্থা');
    }

    protected function getDescription(): ?string
    {
        return __('প্রতি ১০ সেকেন্ডে নিজে থেকে আপডেট হয়');
    }

    protected function getStats(): array
    {
        $in = new Insights(Filament::getTenant()->getKey());
        $today = Insights::todayStartUtc();
        $yesterday = $today->subDay();
        $now = $in->messageCounts($today);
        $prev = $in->messageCounts($yesterday, $today);
        $week = $in->series('day', 7);
        [$opened, $requested] = $in->tickets($today);
        $waiting = $in->waiting();
        $speed = $in->botResponseSeconds($today);

        return [
            Stat::make(__('কাস্টমারের মেসেজ'), $now['customer'])
                ->description(__('গতকাল :nটা', ['n' => $prev['customer']]))
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->chart($week['customer'])->chartColor('primary'),
            Stat::make(__('বটের উত্তর'), $now['bot'])
                ->description($speed === null ? __('আজ এখনো উত্তর দেয়নি') : __('গড়ে :n সেকেন্ডে উত্তর', ['n' => $speed]))
                ->icon(Heroicon::OutlinedCpuChip)
                ->chart($week['bot'])->chartColor('success'),
            Stat::make(__('এজেন্টের উত্তর'), $now['staff'])
                ->description(__('গতকাল :nটা', ['n' => $prev['staff']]))
                ->icon(Heroicon::OutlinedUser)
                ->chart($week['staff'])->chartColor('info'),
            Stat::make(__('উত্তরের অপেক্ষায়'), $waiting)
                ->description($waiting ? __('শেষ মেসেজ কাস্টমারের, এখনো উত্তর যায়নি') : __('সবাই উত্তর পেয়েছে'))
                ->descriptionColor($waiting ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedClock)
                ->url(ConversationResource::getUrl('index')),
            Stat::make(__('নতুন কাস্টমার নম্বর'), $in->newContacts($today))
                ->description(__('আজ প্রথমবার মেসেজ দিয়েছে'))
                ->icon(Heroicon::OutlinedUserPlus),
            Stat::make(__('টিকিট'), $opened)
                ->description($requested > $opened ? __('বট :askedটা চেয়েছে, :openedটা খোলা হয়েছে', ['asked' => $requested, 'opened' => $opened]) : __('আজ বিলিংয়ে খোলা হয়েছে'))
                ->icon(Heroicon::OutlinedTicket),
        ];
    }
}
