<?php

namespace App\Filament\App\Clusters\BillingConfig;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/** বিলিং কনফিগারেশন menu item with its lists as tabs: Zone, Sub Zone, Box, District, Upazila. */
class AreaCluster extends Cluster
{
    protected static ?string $navigationLabel = 'এলাকা';

    protected static ?string $clusterBreadcrumb = 'এলাকা';

    protected static string|\UnitEnum|null $navigationGroup = 'বিলিং কনফিগারেশন';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'billing-config/area';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}