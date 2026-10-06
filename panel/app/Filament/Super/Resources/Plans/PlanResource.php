<?php

namespace App\Filament\Super\Resources\Plans;

use App\Filament\Super\Resources\Plans\Pages\CreatePlan;
use App\Filament\Super\Resources\Plans\Pages\EditPlan;
use App\Filament\Super\Resources\Plans\Pages\ListPlans;
use App\Filament\Super\Resources\Plans\Schemas\PlanForm;
use App\Filament\Super\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'প্যাকেজ';

    protected static ?string $pluralModelLabel = 'প্যাকেজ';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
