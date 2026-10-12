<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\UpazilaList;
use App\Filament\App\Clusters\BillingConfig\AreaCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class UpazilaResource extends BillingConfigResource
{
    protected static string $kind = 'upazilas';

    protected static ?string $cluster = AreaCluster::class;

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $customerColumn = 'thana';

    public static function getPages(): array
    {
        return ['index' => UpazilaList::route('/')];
    }
}