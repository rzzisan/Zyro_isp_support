<?php

namespace App\Filament\App\Resources\StaffTeams\Pages;

use App\Filament\App\Resources\StaffTeams\StaffTeamResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStaffTeams extends ManageRecords
{
    protected static string $resource = StaffTeamResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
