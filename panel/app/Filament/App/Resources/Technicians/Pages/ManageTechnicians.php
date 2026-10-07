<?php

namespace App\Filament\App\Resources\Technicians\Pages;

use App\Filament\App\Resources\Technicians\TechnicianResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTechnicians extends ManageRecords
{
    protected static string $resource = TechnicianResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
