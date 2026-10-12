<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\TicketPriorityList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TicketPriorityResource extends BillingConfigResource
{
    protected static string $kind = 'priorities';

    protected static ?int $navigationSort = 14;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    public static function getPages(): array
    {
        return ['index' => TicketPriorityList::route('/')];
    }
}