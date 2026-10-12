<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\BoxList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class BoxResource extends BillingConfigResource
{
    protected static string $kind = 'boxes';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static function columns(): array
    {
        return [
            TextColumn::make('parent_name')->label('Sub Zone')->sortable()->searchable()->placeholder('—'),
            TextColumn::make('zone_name')->label('Zone')->sortable()->searchable()->placeholder('—'),
        ];
    }

    protected static function customerMatch(): ?string
    {
        return 'c.box = billing_options.name AND (billing_options.parent_name IS NULL OR c.subzone = billing_options.parent_name)';
    }

    public static function getPages(): array
    {
        return ['index' => BoxList::route('/')];
    }
}