<?php

namespace App\Filament\App\Resources\OnuStockEntries;

use App\Filament\App\Resources\OnuStockEntries\Pages\ManageOnuStockEntries;
use App\Models\OnuBrand;
use App\Models\OnuStockEntry;
use App\Support\Onu;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** ONUs bought: brand, quantity, purchase date. */
class OnuStockEntryResource extends Resource
{
    protected static ?string $model = OnuStockEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    public static function getNavigationLabel(): string
    {
        return __('ONU স্টক এন্ট্রি');
    }

    public static function getModelLabel(): string
    {
        return __('স্টক এন্ট্রি');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ONU স্টক এন্ট্রি');
    }

    protected static ?string $slug = 'onu-stock-entries';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Onu::canAccess();
    }

    private static function company(): int
    {
        return (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label(__('ব্র্যান্ড'))->required()
                ->options(fn () => OnuBrand::where('company_id', static::company())->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('quantity')->label(__('পরিমাণ'))->integer()->minValue(1)->required(),
            DatePicker::make('purchase_date')->label(__('কেনার তারিখ'))->required()->default(fn () => now('Asia/Dhaka')->toDateString()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('purchase_date', 'desc')
            ->columns([
                TextColumn::make('brand.name')->label(__('ব্র্যান্ড'))->searchable(),
                TextColumn::make('quantity')->label(__('পরিমাণ')),
                TextColumn::make('purchase_date')->label(__('কেনার তারিখ'))->date('d M Y')->sortable(),
                TextColumn::make('created_at')->label(__('এন্ট্রির সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->toggleable(),
                TextColumn::make('user.name')->label(__('এন্ট্রি করেছেন'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label(__('ব্র্যান্ড'))
                    ->options(fn () => OnuBrand::where('company_id', static::company())->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->headerActions([CreateAction::make()->label(__('স্টক যোগ করুন'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => static::company(), 'user_id' => auth()->id()])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company())->with(['brand', 'user']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOnuStockEntries::route('/')];
    }
}
