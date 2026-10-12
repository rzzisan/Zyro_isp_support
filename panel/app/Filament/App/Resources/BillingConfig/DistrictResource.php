<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\DistrictList;
use App\Filament\App\Clusters\BillingConfig\AreaCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DistrictResource extends BillingConfigResource
{
    protected static string $kind = 'districts';

    protected static ?string $cluster = AreaCluster::class;

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static ?string $customerColumn = 'district';

    public static function getPages(): array
    {
        return ['index' => DistrictList::route('/')];
    }
}