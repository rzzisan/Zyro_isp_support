<?php

namespace App\Filament\App\Resources\BillingConfig\Pages;

use App\Filament\App\Resources\BillingConfig\ConnectionTypeResource;
use App\Filament\App\Resources\BillingConfig\ListBillingConfig;

class ConnectionTypeList extends ListBillingConfig
{
    protected static string $resource = ConnectionTypeResource::class;
}