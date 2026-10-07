<?php

namespace App\Filament\App\Pages;

use App\Filament\App\TicketActions;
use Filament\Pages\Dashboard as BaseDashboard;

/** Company home: live numbers and graphs (widgets in App\Filament\App\Widgets). */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'লাইভ ড্যাশবোর্ড';

    protected static ?string $navigationLabel = 'ড্যাশবোর্ড';

    protected static ?int $navigationSort = -20;

    protected function getHeaderActions(): array
    {
        return [TicketActions::newTicket()];
    }
}
