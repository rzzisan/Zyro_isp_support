<?php

namespace App\Filament\App\Resources\LineEnables\Pages;

use App\Filament\App\Resources\LineEnables\LineEnableResource;
use Filament\Resources\Pages\ListRecords;

class ListLineEnables extends ListRecords
{
    protected static string $resource = LineEnableResource::class;

    protected static ?string $title = 'লাইন চালু/বন্ধের রেকর্ড';

    public function getSubheading(): ?string
    {
        return 'কোন টেকনিশিয়ান WhatsApp-এ কোন কাস্টমারের লাইন চালু বা বন্ধ করতে বলেছেন, আর বট কী করেছে';
    }
}
