<?php

namespace App\Filament\App\Clusters\BillingConfig;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/** বিলিং কনফিগারেশন menu item with its lists as tabs: Zone, Sub Zone, Box, District, Upazila. */
class AreaCluster extends Cluster
{
    use \App\Filament\App\Concerns\QuickAccessible;

    public static function getNavigationLabel(): string
    {
        return __('এলাকা');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('এলাকা');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'বিলিং কনফিগারেশন';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'billing-config/area';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}