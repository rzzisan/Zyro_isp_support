<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\CustomerStatusList;
use App\Filament\App\Clusters\BillingConfig\ServiceCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class CustomerStatusResource extends BillingConfigResource
{
    protected static string $kind = 'customer_statuses';

    protected static ?string $cluster = ServiceCluster::class;

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $customerColumn = 'status';

    protected static function columns(): array
    {
        return [TextColumn::make('short_value')->label('সংক্ষেপ')->placeholder('—')];
    }

    public static function getPages(): array
    {
        return ['index' => CustomerStatusList::route('/')];
    }
}