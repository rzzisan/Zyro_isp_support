<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\ClientTypeList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ClientTypeResource extends BillingConfigResource
{
    protected static string $kind = 'client_types';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $customerColumn = 'customer_type';

    public static function getPages(): array
    {
        return ['index' => ClientTypeList::route('/')];
    }
}