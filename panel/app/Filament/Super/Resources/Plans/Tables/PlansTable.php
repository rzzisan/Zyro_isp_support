<?php

namespace App\Filament\Super\Resources\Plans\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('প্যাকেজ')->searchable(),
                TextColumn::make('price_monthly')->label('মাসিক')->formatStateUsing(fn ($state) => '৳'.number_format($state)),
                TextColumn::make('max_agents')->label('এজেন্ট'),
                TextColumn::make('max_whatsapp_numbers')->label('WhatsApp'),
                TextColumn::make('max_bot_replies')->label('বট উত্তর/মাস')->placeholder('সীমাহীন'),
                TextColumn::make('trial_days')->label('ট্রায়াল')->suffix(' দিন'),
                TextColumn::make('companies_count')->label('কোম্পানি')->counts('companies'),
                IconColumn::make('is_active')->label('চালু')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
