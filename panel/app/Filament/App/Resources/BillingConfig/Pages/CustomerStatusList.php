<?php

namespace App\Filament\App\Resources\BillingConfig\Pages;

use App\Filament\App\Resources\BillingConfig\CustomerStatusResource;
use App\Filament\App\Resources\BillingConfig\ListBillingConfig;

class CustomerStatusList extends ListBillingConfig
{
    protected static string $resource = CustomerStatusResource::class;
}