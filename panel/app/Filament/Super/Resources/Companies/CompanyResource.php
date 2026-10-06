<?php

namespace App\Filament\Super\Resources\Companies;

use App\Filament\Super\Resources\Companies\Pages\CreateCompany;
use App\Filament\Super\Resources\Companies\Pages\EditCompany;
use App\Filament\Super\Resources\Companies\Pages\ListCompanies;
use App\Filament\Super\Resources\Companies\Schemas\CompanyForm;
use App\Filament\Super\Resources\Companies\Tables\CompaniesTable;
use App\Filament\Super\Resources\Companies\RelationManagers\UsersRelationManager;
use App\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'কোম্পানি';

    protected static ?string $pluralModelLabel = 'কোম্পানি';

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
