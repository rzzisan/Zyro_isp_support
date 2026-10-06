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

    protected ?string $heading = 'আজকের অবস্থা';

    protected ?string $description = 'প্রতি ১০ সেকেন্ডে নিজে থেকে আপডেট হয়';

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
            Stat::make('কাস্টমারের মেসেজ', $now['customer'])
                ->description('গতকাল '.$prev['customer'].'টা')
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->chart($week['customer'])->chartColor('primary'),
            Stat::make('বটের উত্তর', $now['bot'])
                ->description($speed === null ? 'আজ এখনো উত্তর দেয়নি' : "গড়ে {$speed} সেকেন্ডে উত্তর")
                ->icon(Heroicon::OutlinedCpuChip)
                ->chart($week['bot'])->chartColor('success'),
            Stat::make('এজেন্টের উত্তর', $now['staff'])
                ->description('গতকাল '.$prev['staff'].'টা')
                ->icon(Heroicon::OutlinedUser)
                ->chart($week['staff'])->chartColor('info'),
            Stat::make('উত্তরের অপেক্ষায়', $waiting)
                ->description($waiting ? 'শেষ মেসেজ কাস্টমারের, এখনো উত্তর যায়নি' : 'সবাই উত্তর পেয়েছে')
                ->descriptionColor($waiting ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedClock)
                ->url(ConversationResource::getUrl('index')),
            Stat::make('নতুন কাস্টমার নম্বর', $in->newContacts($today))
                ->description('আজ প্রথমবার মেসেজ দিয়েছে')
                ->icon(Heroicon::OutlinedUserPlus),
            Stat::make('টিকিট', $opened)
                ->description($requested > $opened ? "বট {$requested}টা চেয়েছে, {$opened}টা খোলা হয়েছে" : 'আজ বিলিংয়ে খোলা হয়েছে')
                ->icon(Heroicon::OutlinedTicket),
        ];
    }
}
