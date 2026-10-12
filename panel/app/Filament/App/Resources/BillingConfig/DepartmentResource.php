<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\DepartmentList;
use App\Filament\App\Clusters\BillingConfig\SupportCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DepartmentResource extends BillingConfigResource
{
    protected static string $kind = 'departments';

    protected static ?string $cluster = SupportCluster::class;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    public static function getPages(): array
    {
        return ['index' => DepartmentList::route('/')];
    }
}