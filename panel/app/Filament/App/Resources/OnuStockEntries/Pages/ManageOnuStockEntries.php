<?php

namespace App\Filament\App\Resources\OnuStockEntries\Pages;

use App\Filament\App\Resources\OnuStockEntries\OnuStockEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOnuStockEntries extends ManageRecords
{
    protected static string $resource = OnuStockEntryResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
