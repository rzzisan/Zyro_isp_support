<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\DistrictList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DistrictResource extends BillingConfigResource
{
    protected static string $kind = 'districts';

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static ?string $customerColumn = 'district';

    public static function getPages(): array
    {
        return ['index' => DistrictList::route('/')];
    }
}