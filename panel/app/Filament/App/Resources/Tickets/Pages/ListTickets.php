<?php

namespace App\Filament\App\Resources\Tickets\Pages;

use App\Filament\App\Resources\Tickets\TicketResource;
use App\Models\BillingTicket;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected static ?string $title = 'বিলিংয়ের টিকিট';

    public function getSubheading(): string|Htmlable|null
    {
        $at = BillingTicket::where('company_id', Filament::getTenant()->getKey())->max('synced_at');

        return $at ? 'বিলিং সফটওয়্যার থেকে প্রতি ৫ মিনিটে আপডেট হয় · শেষ আপডেট '
            .\Illuminate\Support\Carbon::parse($at, 'UTC')->timezone('Asia/Dhaka')->format('g:i A')
            : 'এখনো বিলিং থেকে কোনো টিকিট আসেনি';
    }

    protected function getHeaderActions(): array
    {
        return [\App\Filament\App\TicketActions::newTicket()];
    }

    public function getTabs(): array
    {
        $count = fn (array $states) => BillingTicket::where('company_id', Filament::getTenant()->getKey())
            ->whereIn('state', $states)->count();

        return [
            'open' => Tab::make('খোলা')->badge($count(['pending', 'processing']))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('state', ['pending', 'processing'])),
            'pending' => Tab::make('অপেক্ষমাণ')->badge($count(['pending']))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', 'pending')),
            'processing' => Tab::make('কাজ চলছে')->badge($count(['processing']))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', 'processing')),
            'solved' => Tab::make('সমাধান')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('state', ['solved', 'closed'])),
            'all' => Tab::make('সব'),
        ];
    }
}
