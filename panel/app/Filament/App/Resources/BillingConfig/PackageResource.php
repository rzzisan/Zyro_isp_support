<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Filament\App\Resources\BillingConfig\Pages\PackageList;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class PackageResource extends BillingConfigResource
{
    protected static string $kind = 'packages';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static function columns(): array
    {
        return [
            TextColumn::make('extra.mbps')->label('স্পিড (Mbps)')->placeholder('—'),
            TextColumn::make('extra.price')->label('দাম')->numeric(0)->placeholder('—'),
            TextColumn::make('short_value')->label('ধরন')->badge()
                ->state(fn ($record) => $record->short_value === 'foreveryonepurpose' ? 'Business' : 'Personal'),
            TextColumn::make('extra.vas')->label('VAS')->placeholder('—')->toggleable(),
            TextColumn::make('extra.description')->label('বিবরণ')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected static function customerMatch(): ?string
    {
        return 'c.package_id::text = billing_options.option_id';
    }

    public static function getPages(): array
    {
        return ['index' => PackageList::route('/')];
    }
}