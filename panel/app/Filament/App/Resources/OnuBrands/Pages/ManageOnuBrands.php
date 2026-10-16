<?php

namespace App\Filament\App\Resources\OnuBrands\Pages;

use App\Filament\App\Resources\OnuBrands\OnuBrandResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOnuBrands extends ManageRecords
{
    protected static string $resource = OnuBrandResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
