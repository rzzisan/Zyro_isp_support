<?php

namespace App\Filament\App\Resources\VoiceOptouts\Pages;

use App\Filament\App\Resources\VoiceOptouts\VoiceOptoutResource;
use Filament\Resources\Pages\ManageRecords;

class ManageVoiceOptouts extends ManageRecords
{
    protected static string $resource = VoiceOptoutResource::class;

    protected function getHeaderActions(): array
    {
        return []; // add lives in the table header (sets the company)
    }
}
