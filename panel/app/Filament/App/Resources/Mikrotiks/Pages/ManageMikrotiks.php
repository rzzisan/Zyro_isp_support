<?php

namespace App\Filament\App\Resources\Mikrotiks\Pages;

use App\Filament\App\Resources\Mikrotiks\MikrotikResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMikrotiks extends ManageRecords
{
    protected static string $resource = MikrotikResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('MikroTik রাউটার');
    }

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company, then tests)
    }
}
