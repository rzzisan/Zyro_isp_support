<?php

namespace App\Filament\App\Clusters\BillingConfig;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/** বিলিং কনফিগারেশন menu item with its lists as tabs: কর্মী, ডিপার্টমেন্ট, সমস্যার ধরন, Priority. */
class SupportCluster extends Cluster
{
    protected static ?string $navigationLabel = 'সাপোর্ট তালিকা';

    protected static ?string $clusterBreadcrumb = 'সাপোর্ট তালিকা';

    protected static string|\UnitEnum|null $navigationGroup = 'বিলিং কনফিগারেশন';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'billing-config/support';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}