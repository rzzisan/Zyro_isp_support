<?php

namespace App\Filament\App\Resources\BotFaqs\Pages;

use App\Filament\App\Resources\BotFaqs\BotFaqResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBotFaqs extends ManageRecords
{
    protected static string $resource = BotFaqResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
