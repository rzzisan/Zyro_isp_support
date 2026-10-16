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
                TextColumn::make('name')->label(__('কোম্পানি'))->searchable()->description(fn ($record) => $record->slug),
                TextColumn::make('plan.name')->label(__('প্যাকেজ'))->placeholder('—'),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => ['trial' => __('ট্রায়াল'), 'active' => __('চালু'), 'suspended' => __('বন্ধ')][$state] ?? $state)
                    ->color(fn ($state) => ['trial' => 'warning', 'active' => 'success', 'suspended' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('users_count')->label(__('ইউজার'))->counts('users'),
                TextColumn::make('trial_ends_at')->label(__('ট্রায়াল শেষ'))->date()->placeholder('—'),
                TextColumn::make('subscription_ends_at')->label(__('সাবস্ক্রিপশন শেষ'))->date()->placeholder('—'),
                TextColumn::make('contact_phone')->label(__('ফোন'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('তৈরি'))->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))
                    ->options(['trial' => __('ট্রায়াল'), 'active' => __('চালু'), 'suspended' => __('বন্ধ')]),
                SelectFilter::make('plan_id')->label(__('প্যাকেজ'))->relationship('plan', 'name'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
