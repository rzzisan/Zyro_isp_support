<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\TicketCategoryList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TicketCategoryResource extends BillingConfigResource
{
    protected static string $kind = 'categories';

    protected static ?int $navigationSort = 13;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function getPages(): array
    {
        return ['index' => TicketCategoryList::route('/')];
    }
}