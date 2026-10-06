<?php

namespace App\Filament\App\Resources\WaAccounts\Pages;

use App\Filament\App\Resources\WaAccounts\WaAccountResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWaAccounts extends ManageRecords
{
    protected static string $resource = WaAccountResource::class;

    protected static ?string $title = 'WhatsApp নম্বর';

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
