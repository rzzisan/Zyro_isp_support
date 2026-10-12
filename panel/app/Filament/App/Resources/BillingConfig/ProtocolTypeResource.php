<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\ProtocolTypeList;
use App\Filament\App\Clusters\BillingConfig\ServiceCluster;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class ProtocolTypeResource extends BillingConfigResource
{
    protected static string $kind = 'protocol_types';

    protected static ?string $cluster = ServiceCluster::class;

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $customerColumn = 'protocol';

    protected static function columns(): array
    {
        return [TextColumn::make('short_value')->label('সংক্ষেপ')->placeholder('—')];
    }

    public static function getPages(): array
    {
        return ['index' => ProtocolTypeList::route('/')];
    }
}