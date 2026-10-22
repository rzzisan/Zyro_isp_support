<?php

namespace App\Filament\Super\Resources\SystemBackups\Pages;

use App\Filament\Super\Resources\SystemBackups\SystemBackupResource;
use App\Filament\Super\Resources\SystemBackups\Widgets\BackupStatus;
use Filament\Resources\Pages\ManageRecords;

class ManageSystemBackups extends ManageRecords
{
    protected static string $resource = SystemBackupResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('সিস্টেম ব্যাকআপ');
    }

    protected function getHeaderWidgets(): array
    {
        return [BackupStatus::class];
    }

    protected function getHeaderActions(): array
    {
        return SystemBackupResource::headerActions(); // rows are written by engine/system_backup.py only
    }
}
