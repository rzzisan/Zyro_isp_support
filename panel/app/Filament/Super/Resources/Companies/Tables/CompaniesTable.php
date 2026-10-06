<?php

namespace App\Filament\Super\Resources\Companies\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('কোম্পানি')->searchable()->description(fn ($record) => $record->slug),
                TextColumn::make('plan.name')->label('প্যাকেজ')->placeholder('—'),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->formatStateUsing(fn ($state) => ['trial' => 'ট্রায়াল', 'active' => 'চালু', 'suspended' => 'বন্ধ'][$state] ?? $state)
                    ->color(fn ($state) => ['trial' => 'warning', 'active' => 'success', 'suspended' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('users_count')->label('ইউজার')->counts('users'),
                TextColumn::make('trial_ends_at')->label('ট্রায়াল শেষ')->date()->placeholder('—'),
                TextColumn::make('subscription_ends_at')->label('সাবস্ক্রিপশন শেষ')->date()->placeholder('—'),
                TextColumn::make('contact_phone')->label('ফোন')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('তৈরি')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('অবস্থা')
                    ->options(['trial' => 'ট্রায়াল', 'active' => 'চালু', 'suspended' => 'বন্ধ']),
                SelectFilter::make('plan_id')->label('প্যাকেজ')->relationship('plan', 'name'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
