<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\ConnectionTypeList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ConnectionTypeResource extends BillingConfigResource
{
    protected static string $kind = 'connection_types';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $customerColumn = 'connection_type';

    public static function getPages(): array
    {
        return ['index' => ConnectionTypeList::route('/')];
    }
}