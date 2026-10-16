<?php

namespace App\Filament\App\Resources\NewConnections\Pages;

use App\Filament\App\Resources\NewConnections\NewConnectionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageNewConnections extends ManageRecords
{
    protected static string $resource = NewConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
