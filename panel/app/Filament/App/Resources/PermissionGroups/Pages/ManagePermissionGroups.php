<?php

namespace App\Filament\App\Resources\PermissionGroups\Pages;

use App\Filament\App\Resources\PermissionGroups\PermissionGroupResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePermissionGroups extends ManageRecords
{
    protected static string $resource = PermissionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header
    }
}
