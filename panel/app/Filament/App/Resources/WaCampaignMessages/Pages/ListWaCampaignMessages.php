<?php

namespace App\Filament\App\Resources\WaCampaignMessages\Pages;

use App\Filament\App\Resources\WaCampaignMessages\WaCampaignMessageResource;
use App\Filament\App\WaCampaignActions;
use Filament\Resources\Pages\ListRecords;

class ListWaCampaignMessages extends ListRecords
{
    protected static string $resource = WaCampaignMessageResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('WhatsApp লগ');
    }

    public function getSubheading(): ?string
    {
        return __('Desk থেকে পাঠানো প্রতিটা WhatsApp টেমপ্লেট মেসেজ। "পৌঁছেছে" আর "পড়েছেন" Meta নিজে জানায়; ব্যর্থ হলে Meta-র কারণ দেখায়।');
    }

    protected function getHeaderActions(): array
    {
        return [WaCampaignActions::send()];
    }
}
