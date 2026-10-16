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
                TextColumn::make('name')->label(__('প্যাকেজ'))->searchable(),
                TextColumn::make('price_monthly')->label(__('মাসিক'))->formatStateUsing(fn ($state) => __('৳').number_format($state)),
                TextColumn::make('max_agents')->label(__('এজেন্ট')),
                TextColumn::make('max_whatsapp_numbers')->label('WhatsApp'),
                TextColumn::make('max_bot_replies')->label(__('বট উত্তর/মাস'))->placeholder(__('সীমাহীন')),
                TextColumn::make('trial_days')->label(__('ট্রায়াল'))->formatStateUsing(fn ($state) => __(':n দিন', ['n' => $state])),
                TextColumn::make('companies_count')->label(__('কোম্পানি'))->counts('companies'),
                IconColumn::make('is_active')->label(__('চালু'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
