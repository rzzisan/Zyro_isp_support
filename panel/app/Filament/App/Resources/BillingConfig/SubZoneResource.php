<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\SubZoneList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class SubZoneResource extends BillingConfigResource
{
    protected static string $kind = 'subzones';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static function columns(): array
    {
        return [TextColumn::make('parent_name')->label('Zone')->sortable()->searchable()->placeholder('—')];
    }

    protected static function customerMatch(): ?string
    {
        return 'c.subzone = billing_options.name AND (billing_options.parent_name IS NULL OR c.zone = billing_options.parent_name)';
    }

    public static function getPages(): array
    {
        return ['index' => SubZoneList::route('/')];
    }
}