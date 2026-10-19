<?php

namespace App\Filament\App\Resources\MikrotikBackups\Pages;

use App\Filament\App\Resources\MikrotikBackups\MikrotikBackupResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMikrotikBackups extends ManageRecords
{
    protected static string $resource = MikrotikBackupResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('MikroTik ব্যাকআপ');
    }

    protected function getHeaderActions(): array
    {
        return []; // "এখনই ব্যাকআপ" lives in the table header; rows are written by the engine only
    }
}
