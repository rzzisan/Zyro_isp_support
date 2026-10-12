<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\UpazilaList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class UpazilaResource extends BillingConfigResource
{
    protected static string $kind = 'upazilas';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $customerColumn = 'thana';

    public static function getPages(): array
    {
        return ['index' => UpazilaList::route('/')];
    }
}