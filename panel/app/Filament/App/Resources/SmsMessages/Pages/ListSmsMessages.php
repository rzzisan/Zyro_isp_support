<?php

namespace App\Filament\App\Resources\SmsMessages\Pages;

use App\Filament\App\Resources\SmsMessages\SmsMessageResource;
use App\Filament\App\SmsActions;
use Filament\Resources\Pages\ListRecords;

class ListSmsMessages extends ListRecords
{
    protected static string $resource = SmsMessageResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('SMS লগ');
    }

    public function getSubheading(): ?string
    {
        return 'Desk থেকে পাঠানো প্রতিটা SMS। "গেটওয়েতে গেছে" মানে গেটওয়ে নিয়েছে; পৌঁছানোর খবর গেটওয়ে থেকে কয়েক মিনিট পরপর আনা হয়।';
    }

    protected function getHeaderActions(): array
    {
        return [SmsActions::send()];
    }
}
