<?php

namespace App\Filament\App\Resources\Onus;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\Onus\Pages\ListOnus;
use App\Models\Olt;
use App\Models\Onu;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Every ONU on our OLTs with live-ish status and light levels, and the customer behind it. Read-only. */
class OnuResource extends Resource
{
    protected static ?string $model = Onu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $navigationLabel = 'ONU মনিটরিং';

    protected static ?string $modelLabel = 'ONU';

    protected static ?string $pluralModelLabel = 'ONU';

    protected static ?string $slug = 'onus';

    protected static ?int $navigationSort = -6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('onus') && Olt::where('company_id', Filament::getTenant()?->getKey() ?? 0)->exists();
    }

    /** Correlated subquery: a field of the billing customer whose router MAC sits behind this ONU. */
    private static function customerField(string $field)
    {
        return DB::table('customer_onus as m')
            ->join('ppp_sessions as s', fn ($j) => $j->on('s.company_id', '=', 'm.company_id')->whereRaw('upper(s.caller_id) = m.client_mac'))
            ->join('billing_customers as b', fn ($j) => $j->on('b.company_id', '=', 's.company_id')->on('b.username', '=', 's.username'))
            ->whereColumn('m.onu_id', 'onus.id')->orderByDesc('s.seen_at')->limit(1)->select("b.{$field}");
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('olt')->select('onus.*')->addSelect([
                'c_id' => static::customerField('customer_id'), 'c_name' => static::customerField('name'),
                'c_user' => static::customerField('username'), 'c_row' => static::customerField('id'),
            ]))
            ->poll('120s')
            ->defaultSort('name')
            ->paginated([25, 50, 100, 250])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('olt.name')->label('OLT')->toggleable(),
                TextColumn::make('name')->label('পোর্ট')->sortable()->searchable(),
                TextColumn::make('online')->label('অবস্থা')->badge()
                    ->state(fn (Onu $record) => $record->online ? 'অনলাইন' : 'অফলাইন')
                    ->color(fn (string $state) => $state === 'অনলাইন' ? 'success' : 'danger'),
                TextColumn::make('rx_dbm')->label('Rx power')->sortable()->suffix(' dBm')->placeholder('—')
                    ->color(fn (?float $state) => $state === null ? null : ($state < Onu::WEAK_DBM ? 'danger' : ($state < -25 ? 'warning' : 'success'))),
                TextColumn::make('tx_dbm')->label('Tx')->suffix(' dBm')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('distance_m')->label('দূরত্ব')->suffix(' m')->sortable()->placeholder('—'),
                TextColumn::make('temp_c')->label('তাপমাত্রা')->suffix('°C')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('c_name')->label('কাস্টমার')->placeholder('মেলেনি')
                    ->description(fn (Onu $record) => $record->c_id ? 'ID '.$record->c_id.' · '.$record->c_user : null)
                    ->url(fn (Onu $record) => $record->c_row ? CustomerResource::getUrl('view', ['record' => $record->c_row]) : null),
                TextColumn::make('last_change_at')->label('শেষ অবস্থা বদল')->dateTime('d M, g:i A', 'Asia/Dhaka')->sortable()->placeholder('—'),
                TextColumn::make('onu_mac')->label('ONU MAC')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('olt_id')->label('OLT')
                    ->options(fn () => Olt::where('company_id', Filament::getTenant()->getKey())->pluck('name', 'id')->all()),
                SelectFilter::make('pon')->label('PON পোর্ট')
                    ->options(fn () => Onu::where('company_id', Filament::getTenant()->getKey())
                        ->selectRaw("split_part(name, ':', 1) AS p")->distinct()->orderBy('p')->pluck('p', 'p')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($q, $v) => $q->where('onus.name', 'like', $v.':%'))),
                TernaryFilter::make('online')->label('অবস্থা')->trueLabel('অনলাইন')->falseLabel('অফলাইন'),
                TernaryFilter::make('weak')->label('Power')->trueLabel('দুর্বল (-27 dBm-এর নিচে)')->falseLabel('ঠিক আছে')
                    ->queries(true: fn (Builder $query) => $query->where('rx_dbm', '<', Onu::WEAK_DBM),
                        false: fn (Builder $query) => $query->where('rx_dbm', '>=', Onu::WEAK_DBM)),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(4);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('onus.company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListOnus::route('/')];
    }
}
