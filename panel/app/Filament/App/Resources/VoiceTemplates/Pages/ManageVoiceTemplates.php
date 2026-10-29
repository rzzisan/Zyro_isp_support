<?php

namespace App\Filament\App\Resources\VoiceTemplates\Pages;

use App\Filament\App\Resources\VoiceTemplates\VoiceTemplateResource;
use App\Models\VoiceTemplate;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageVoiceTemplates extends ManageRecords
{
    protected static string $resource = VoiceTemplateResource::class;

    public function mount(): void
    {
        VoiceTemplate::seed((int) Filament::getTenant()->getKey());   // first visit: the default messages
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
