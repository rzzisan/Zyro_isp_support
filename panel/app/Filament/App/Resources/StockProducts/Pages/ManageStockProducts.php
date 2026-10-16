<?php

namespace App\Filament\App\Resources\StockProducts\Pages;

use App\Filament\App\Resources\StockProducts\StockProductResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStockProducts extends ManageRecords
{
    protected static string $resource = StockProductResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
