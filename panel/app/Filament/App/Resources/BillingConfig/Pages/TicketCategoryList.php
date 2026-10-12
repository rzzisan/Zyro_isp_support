<?php

namespace App\Filament\App\Resources\BillingConfig\Pages;

use App\Filament\App\Resources\BillingConfig\TicketCategoryResource;
use App\Filament\App\Resources\BillingConfig\ListBillingConfig;

class TicketCategoryList extends ListBillingConfig
{
    protected static string $resource = TicketCategoryResource::class;
}