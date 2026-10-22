<?php

namespace App\Filament\App\Resources\StockMovements;

use App\Filament\App\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\StockMovement;
use App\Models\StockProduct;
use App\Models\Technician;
use App\Support\Onu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Log of other-stock movements (in / handed out). Deleting one puts the count back. */
class StockMovementResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getNavigationLabel(): string
    {
        return __('মালের হিসাব (ইন/বিতরণ)');
    }

    public static function getModelLabel(): string
    {
        return __('মালের হিসাব');
    }

    public static function getPluralModelLabel(): string
    {
        return __('মালের হিসাব (ইন/বিতরণ)');
    }

    protected static ?string $slug = 'stock-movements';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return Onu::canAccess();
    }

    public static function canCreate(): bool
    {
        return false; // from অন্যান্য মাল → স্টক ইন / বিতরণ
    }

    private static function company(): int
    {
        return (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('নতুন স্টক ইন বা বিতরণ: "অন্যান্য মাল" পেজে প্রোডাক্টের পাশের বাটন। ভুল এন্ট্রি মুছলে স্টকের সংখ্যা আগের মতো হয়ে যায়।'))
            ->defaultSort('moved_on', 'desc')
            ->paginated([25, 50, 100, 250])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('moved_on')->label(__('তারিখ'))->date('d M Y')->sortable(),
                TextColumn::make('product.name')->label(__('প্রোডাক্ট'))->searchable(),
                TextColumn::make('type')->label(__('ধরন'))->badge()->formatStateUsing(fn (string $state) => __(StockMovement::TYPES[$state] ?? $state))
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'warning'),
                TextColumn::make('quantity')->label(__('পরিমাণ')),
                TextColumn::make('who')->label(__('কর্মচারী / মূল্য'))->state(fn (StockMovement $r) => $r->type === 'in'
                    ? number_format((float) $r->unit_price, 2).' × '.$r->quantity.' = '.number_format((float) $r->total_price, 2).__(' টাকা')
                    : ($r->technician?->name ?? '—')),
                TextColumn::make('reference')->label(__('রেফারেন্স'))->placeholder('—')->searchable(),
                TextColumn::make('user.name')->label(__('এন্ট্রি করেছেন'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date')->schema([DatePicker::make('from')->label(__('শুরুর তারিখ')), DatePicker::make('until')->label(__('শেষ তারিখ'))])
                    ->columns(2)->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('moved_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('moved_on', '<=', $d))),
                SelectFilter::make('product_id')->label(__('প্রোডাক্ট'))
                    ->options(fn () => StockProduct::where('company_id', static::company())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('type')->label(__('ধরন'))->options(array_map('__', StockMovement::TYPES)),
                SelectFilter::make('technician_id')->label(__('কর্মচারী'))->searchable()->options(fn () => Technician::pickerOptions(static::company())),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->recordActions([
                Action::make('undo')->label(__('মুছুন'))->icon(\Filament\Support\Icons\Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()->modalHeading(__('এই এন্ট্রি মুছবেন?'))
                    ->modalDescription(fn (StockMovement $r) => ($r->type === 'in' ? __('মুছলে ":name"-এর স্টক :n কমবে।', ['name' => $r->product?->name, 'n' => $r->quantity])
                        : __('মুছলে ":name"-এর স্টক :n বাড়বে।', ['name' => $r->product?->name, 'n' => $r->quantity])))
                    ->action(fn (StockMovement $record) => $record->undo()),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company())->with(['product', 'technician', 'user']);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
