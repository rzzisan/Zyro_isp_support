<?php

namespace App\Filament\App\Resources\Customers\Widgets;

use App\Filament\App\TicketActions;
use App\Models\BillingCustomer;
use Filament\Widgets\Widget;

/** Customer page: the same live box as the new-ticket form (bill, MikroTik now, OLT/ONU now). Loads after the page. */
class CustomerLiveInfo extends Widget
{
    protected string $view = 'filament.app.customers.live-info-widget';

    protected int|string|array $columnSpan = 'full';

    public ?BillingCustomer $record = null;

    public bool $fresh = false;

    public function refresh(): void
    {
        $this->fresh = true;
    }

    protected function getViewData(): array
    {
        [$info, $error] = TicketActions::liveInfo((int) $this->record?->header_id, $this->fresh);
        $this->fresh = false;

        return ['info' => $info, 'error' => $error];
    }
}
