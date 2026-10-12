<?php

namespace App\Filament\App\Resources\BillingConfig\Pages;

use App\Filament\App\Resources\BillingConfig\TicketPriorityResource;
use App\Filament\App\Resources\BillingConfig\ListBillingConfig;

class TicketPriorityList extends ListBillingConfig
{
    protected static string $resource = TicketPriorityResource::class;
}