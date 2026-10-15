<?php

namespace App\Filament\App\Resources\SmsTemplates\Pages;

use App\Filament\App\Resources\SmsTemplates\SmsTemplateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSmsTemplates extends ManageRecords
{
    protected static string $resource = SmsTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return []; // create lives in the table header (sets the company)
    }
}
