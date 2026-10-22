<?php

namespace App\Filament\App\Resources\AreaStaff;

use App\Filament\App\Resources\AreaStaff\Pages\ListAreaStaff;
use App\Models\AreaStaff;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Who worked tickets in which zone / sub zone / box, as the ticket sync keeps it. Only "pinned" is edited here. */
class AreaStaffResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = AreaStaff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    public static function getNavigationLabel(): string
    {
        return __('এলাকার কর্মী');
    }

    public static function getModelLabel(): string
    {
        return __('এলাকার কর্মী');
    }

    public static function getPluralModelLabel(): string
    {
        return __('এলাকার কর্মী');
    }

    protected static ?string $slug = 'area-staff';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('tickets');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;
        $distinct = fn (string $col) => AreaStaff::where('company_id', $company())->where($col, '<>', '')->distinct()
            ->orderBy($col)->pluck($col, $col)->all();

        return $table
            ->defaultSort('last_worked_at', 'desc')
            ->columns([
                TextColumn::make('zone')->label(__('জোন'))->searchable()->sortable(),
                TextColumn::make('subzone')->label(__('সাব-জোন'))->placeholder('—')->searchable()->sortable(),
                TextColumn::make('box')->label(__('বক্স'))->placeholder('—')->searchable()->sortable(),
                TextColumn::make('employee_name')->label(__('কর্মী'))->weight('medium')->searchable()->sortable(),
                TextColumn::make('tickets')->label(__('টিকিট'))->numeric()->sortable(),
                TextColumn::make('last_worked_at')->label(__('শেষ কাজ'))->dateTime('d M Y', 'Asia/Dhaka')->sortable()
                    ->description(fn (AreaStaff $r) => $r->last_complain_id ? '#'.$r->last_complain_id : null),
                ToggleColumn::make('pinned')->label(__('পিন'))
                    ->tooltip(__('পিন করলে এই এলাকার নতুন টিকিট আগে এই কর্মী পাবেন'))
                    ->disabled(fn () => ! auth()->user()?->managesCompany(Filament::getTenant())),
            ])
            ->filters([
                SelectFilter::make('zone')->label(__('জোন'))->searchable()->options(fn () => $distinct('zone')),
                SelectFilter::make('subzone')->label(__('সাব-জোন'))->searchable()->options(fn () => $distinct('subzone')),
                SelectFilter::make('employee_name')->label(__('কর্মী'))->searchable()->options(fn () => $distinct('employee_name')),
                SelectFilter::make('pinned')->label(__('পিন'))->options(['1' => __('পিন করা')]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListAreaStaff::route('/')];
    }
}
