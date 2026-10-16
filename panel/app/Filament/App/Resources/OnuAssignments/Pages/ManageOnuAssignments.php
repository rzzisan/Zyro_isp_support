<?php

namespace App\Filament\App\Resources\OnuAssignments\Pages;

use App\Filament\App\Resources\OnuAssignments\OnuAssignmentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOnuAssignments extends ManageRecords
{
    protected static string $resource = OnuAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
