<?php

namespace App\Filament\App\Resources\StockProducts;

use App\Filament\App\Resources\StockProducts\Pages\ManageStockProducts;
use App\Models\StockProduct;
use App\Models\Technician;
use App\Support\Onu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** Other stock items (splitter, patch cord, …): count, stock in, hand out to a technician. */
class StockProductResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = StockProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function getNavigationLabel(): string
    {
        return __('অন্যান্য মাল');
    }

    public static function getModelLabel(): string
    {
        return __('প্রোডাক্ট');
    }

    public static function getPluralModelLabel(): string
    {
        return __('অন্যান্য মাল');
    }

    protected static ?string $slug = 'stock-products';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 5;

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
            TextInput::make('name')->label(__('প্রোডাক্টের নাম'))->required()->maxLength(150)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', static::company()))
                ->validationMessages(['unique' => __('এই নামের প্রোডাক্ট আগেই আছে')]),
            TextInput::make('maker')->label(__('কোম্পানি (ঐচ্ছিক)'))->maxLength(150),
        ]);
    }

    /** Runs a stock in/out and tells the user (out fails when stock is short). */
    private static function move(StockProduct $p, string $type, array $data): void
    {
        try {
            $p->move($type, (int) $data['quantity'], array_merge(array_diff_key($data, ['quantity' => 1]), ['user_id' => auth()->id()]));
            Notification::make()->success()->title($type === 'in' ? __('স্টক যোগ হয়েছে') : __('বিতরণ সেভ হয়েছে'))
                ->body(__('এখন স্টকে ').$p->current_stock)->send();
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title(__('সেভ হয়নি'))->body($e->getMessage())->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('প্রোডাক্ট'))->searchable()->sortable(),
                TextColumn::make('maker')->label(__('কোম্পানি'))->placeholder('—'),
                TextColumn::make('current_stock')->label(__('স্টকে আছে'))->weight('bold')->sortable()
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'danger'),
            ])
            ->headerActions([CreateAction::make()->label(__('প্রোডাক্ট যোগ করুন'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => static::company(), 'current_stock' => 0])])
            ->recordActions([
                Action::make('stockIn')->label(__('স্টক ইন'))->icon(Heroicon::OutlinedArrowDownTray)->color('success')
                    ->fillForm(['moved_on' => now('Asia/Dhaka')->toDateString()])
                    ->schema([
                        DatePicker::make('moved_on')->label(__('তারিখ'))->required(),
                        TextInput::make('quantity')->label(__('পরিমাণ'))->integer()->minValue(1)->required(),
                        TextInput::make('unit_price')->label(__('একক দাম'))->numeric()->minValue(0)->required(),
                    ])
                    ->action(fn (StockProduct $record, array $data) => static::move($record, 'in', $data)),
                Action::make('stockOut')->label(__('বিতরণ'))->icon(Heroicon::OutlinedArrowUpTray)->color('warning')
                    ->fillForm(['moved_on' => now('Asia/Dhaka')->toDateString()])
                    ->schema([
                        DatePicker::make('moved_on')->label(__('তারিখ'))->required(),
                        TextInput::make('quantity')->label(__('পরিমাণ'))->integer()->minValue(1)->required()
                            ->helperText(fn (StockProduct $record) => __('স্টকে আছে ').$record->current_stock),
                        Select::make('technician_id')->label(__('কর্মী'))->required()->searchable()
                            ->options(fn () => Technician::pickerOptions(static::company())),
                        TextInput::make('reference')->label(__('রেফারেন্স (কাস্টমার ID / কোথায়)'))->maxLength(100),
                    ])
                    ->action(fn (StockProduct $record, array $data) => static::move($record, 'out', $data)),
                EditAction::make(),
                DeleteAction::make()->before(function (StockProduct $record, DeleteAction $action) {
                    if ($record->movements()->exists()) {
                        Notification::make()->danger()->title(__('ডিলিট করা যাবে না'))->body(__('এই প্রোডাক্টের স্টক ইন/বিতরণের রেকর্ড আছে।'))->send();
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
        return ['index' => ManageStockProducts::route('/')];
    }
}
