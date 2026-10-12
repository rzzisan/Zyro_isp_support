<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\EmployeeList;
use App\Filament\App\Clusters\BillingConfig\SupportCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class EmployeeResource extends BillingConfigResource
{
    protected static string $kind = 'employees';

    protected static ?string $cluster = SupportCluster::class;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public static function getPages(): array
    {
        return ['index' => EmployeeList::route('/')];
    }
}