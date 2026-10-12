<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\EmployeeList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class EmployeeResource extends BillingConfigResource
{
    protected static string $kind = 'employees';

    protected static ?int $navigationSort = 11;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public static function getPages(): array
    {
        return ['index' => EmployeeList::route('/')];
    }
}