<?php

namespace App\Filament\App\Resources\Olts\Pages;

use App\Filament\App\Resources\Olts\OltResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOlts extends ManageRecords
{
    protected static string $resource = OltResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company, then tests)
    }
}
