<?php

namespace App\Filament\App\Resources\AiKeys\Pages;

use App\Filament\App\Resources\AiKeys\AiKeyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAiKeys extends ManageRecords
{
    protected static string $resource = AiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (with seat/role checks)
    }
}
