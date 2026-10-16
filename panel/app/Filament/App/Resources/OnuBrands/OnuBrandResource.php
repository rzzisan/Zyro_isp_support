<?php

namespace App\Filament\App\Resources\OnuBrands;

use App\Filament\App\Resources\OnuBrands\Pages\ManageOnuBrands;
use App\Models\OnuBrand;
use App\Support\Onu;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** ONU brands with price and stock: bought (stock entries) − handed out (assignments) = left. */
class OnuBrandResource extends Resource
{
    protected static ?string $model = OnuBrand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'ONU ব্র্যান্ড ও স্টক';

    protected static ?string $modelLabel = 'ব্র্যান্ড';

    protected static ?string $pluralModelLabel = 'ONU ব্র্যান্ড ও স্টক';

    protected static ?string $slug = 'onu-brands';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 2;

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
            TextInput::make('name')->label('ব্র্যান্ডের নাম')->required()->maxLength(100)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', static::company()))
                ->validationMessages(['unique' => 'এই নামের ব্র্যান্ড আগেই আছে']),
            TextInput::make('price')->label('দাম')->numeric()->minValue(0)->default(0)->suffix('টাকা')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('স্টক = কেনা (স্টক এন্ট্রি) − দেওয়া (ONU বরাদ্দ)। স্টক শেষ হলে ONU বরাদ্দের ফর্মে ব্র্যান্ডটা আর আসে না।')
            ->modifyQueryUsing(fn (Builder $query) => $query->select('onu_brands.*')
                ->selectSub('SELECT coalesce(sum(quantity), 0) FROM onu_stock_entries e WHERE e.brand_id = onu_brands.id', 'in_qty')
                ->selectSub('SELECT count(*) FROM onu_assignments a WHERE a.brand_id = onu_brands.id', 'out_qty')
                ->selectRaw('(SELECT coalesce(sum(quantity), 0) FROM onu_stock_entries e WHERE e.brand_id = onu_brands.id)
                    - (SELECT count(*) FROM onu_assignments a WHERE a.brand_id = onu_brands.id) AS left_qty'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('ব্র্যান্ড')->searchable()->sortable(),
                TextColumn::make('price')->label('দাম')->numeric(0)->suffix(' টাকা')->sortable(),
                TextColumn::make('in_qty')->label('কেনা')->sortable(),
                TextColumn::make('out_qty')->label('দেওয়া')->sortable(),
                TextColumn::make('left_qty')->label('স্টকে আছে')->weight('bold')->sortable()
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'danger'),
            ])
            ->headerActions([CreateAction::make()->label('ব্র্যান্ড যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => static::company()])])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->before(function (OnuBrand $record, DeleteAction $action) {
                    if ($record->stockEntries()->exists() || $record->assignments()->exists()) {
                        Notification::make()->danger()->title('ডিলিট করা যাবে না')
                            ->body('এই ব্র্যান্ডের স্টক এন্ট্রি বা ONU বরাদ্দ আছে।')->send();
                        $action->cancel();
                    }
                }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company());
    }

    public static function getPages(): array
    {
        return ['index' => ManageOnuBrands::route('/')];
    }
}
