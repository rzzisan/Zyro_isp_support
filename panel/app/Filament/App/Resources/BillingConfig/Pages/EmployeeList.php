<?php

namespace App\Filament\App\Resources\BillingConfig\Pages;

use App\Filament\App\Resources\BillingConfig\EmployeeResource;
use App\Filament\App\Resources\BillingConfig\ListBillingConfig;

class EmployeeList extends ListBillingConfig
{
    protected static string $resource = EmployeeResource::class;
}