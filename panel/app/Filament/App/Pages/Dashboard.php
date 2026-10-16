<?php

namespace App\Filament\App\Pages;

use App\Filament\App\TicketActions;
use Filament\Pages\Dashboard as BaseDashboard;

/** Company home: live numbers and graphs (widgets in App\Filament\App\Widgets). */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('লাইভ ড্যাশবোর্ড');
    }

    public static function getNavigationLabel(): string
    {
        return __('ড্যাশবোর্ড');
    }

    protected static ?int $navigationSort = -20;

    protected function getHeaderActions(): array
    {
        return [TicketActions::newTicket()];
    }
}
