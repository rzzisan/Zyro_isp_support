<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\ZoneList;
use App\Filament\App\Clusters\BillingConfig\AreaCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ZoneResource extends BillingConfigResource
{
    protected static string $kind = 'zones';

    protected static ?string $cluster = AreaCluster::class;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $customerColumn = 'zone';

    public static function getPages(): array
    {
        return ['index' => ZoneList::route('/')];
    }
}