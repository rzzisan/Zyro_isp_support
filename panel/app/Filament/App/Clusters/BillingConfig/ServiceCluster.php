<?php

namespace App\Filament\App\Clusters\BillingConfig;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/** বিলিং কনফিগারেশন menu item with its lists as tabs: Package, Connection Type, Client Type, Protocol Type, Customer Status. */
class ServiceCluster extends Cluster
{
    protected static ?string $navigationLabel = 'প্যাকেজ ও সংযোগ';

    protected static ?string $clusterBreadcrumb = 'প্যাকেজ ও সংযোগ';

    protected static string|\UnitEnum|null $navigationGroup = 'বিলিং কনফিগারেশন';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'billing-config/service';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}