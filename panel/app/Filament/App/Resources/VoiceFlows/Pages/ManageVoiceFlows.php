<?php

namespace App\Filament\App\Resources\VoiceFlows\Pages;

use App\Filament\App\Resources\VoiceFlows\VoiceFlowResource;
use App\Models\VoiceFlow;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageVoiceFlows extends ManageRecords
{
    protected static string $resource = VoiceFlowResource::class;

    public function mount(): void
    {
        VoiceFlow::seed((int) Filament::getTenant()->getKey());   // first visit: the two default flows and their messages
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
