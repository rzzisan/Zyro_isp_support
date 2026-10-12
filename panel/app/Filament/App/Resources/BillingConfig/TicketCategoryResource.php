<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\TicketCategoryList;
use App\Filament\App\Clusters\BillingConfig\SupportCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TicketCategoryResource extends BillingConfigResource
{
    protected static string $kind = 'categories';

    protected static ?string $cluster = SupportCluster::class;

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function getPages(): array
    {
        return ['index' => TicketCategoryList::route('/')];
    }
}