<?php

namespace App\Filament\App\Resources\StockMovements\Pages;

use App\Filament\App\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;
}
