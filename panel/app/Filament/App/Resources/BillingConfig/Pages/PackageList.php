<?php

namespace App\Filament\App\Resources\BillingConfig\Pages;

use App\Filament\App\Resources\BillingConfig\PackageResource;
use App\Filament\App\Resources\BillingConfig\ListBillingConfig;

class PackageList extends ListBillingConfig
{
    protected static string $resource = PackageResource::class;
}