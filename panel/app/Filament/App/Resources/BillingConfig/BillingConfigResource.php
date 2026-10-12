<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Models\BillingOption;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One billing list kept in the desk (billing_options of one kind): a read-only copy of billing's page, refreshed
 * only by the page's Sync button. Each list is a small subclass with its own menu item. Owner/Admin only.
 */
abstract class BillingConfigResource extends Resource
{
    /** Lists that have billing's own on/off switch (billing_enabled). */
    private const SWITCH_KINDS = ['zones', 'connection_types', 'client_types', 'protocol_types', 'customer_statuses', 'packages',
        'districts', 'upazilas'];

    /** BillingOption::KINDS key */
    protected static string $kind;

    protected static ?string $model = BillingOption::class;

    protected static string|\UnitEnum|null $navigationGroup = 'বিলিং কনফিগারেশন';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    /** billing_customers column holding this list's name, for the customer count (null = no count). */
    protected static ?string $customerColumn = null;

    public static function kind(): string
    {
        return static::$kind;
    }

    public static function getNavigationLabel(): string
    {
        return BillingOption::KINDS[static::$kind];
    }

    public static function getModelLabel(): string
    {
        return BillingOption::KINDS[static::$kind];
    }

    public static function getPluralModelLabel(): string
    {
        return BillingOption::KINDS[static::$kind];
    }

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'billing-config/'.str_replace('_', '-', static::$kind);
    }

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('billing_config') && (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0)->where('kind', static::$kind);
    }

    /** Extra columns of this list (parent zone, package price, ...). */
    protected static function columns(): array
    {
        return [];
    }

    /** SQL condition matching billing_customers c to this list's row (billing_options), or null for no customer count. */
    protected static function customerMatch(): ?string
    {
        // case-insensitive: the customer list says "PPPOE" where the protocol list says "PPPoE"
        return static::$customerColumn ? 'lower(c.'.static::$customerColumn.') = lower(billing_options.name)' : null;
    }

    public static function table(Table $table): Table
    {
        $match = static::customerMatch();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $match ? $query->select('billing_options.*')->selectSub(
                DB::table('billing_customers as c')->selectRaw('count(*)')->whereNull('c.gone_at')->where('c.is_left', false)
                    ->whereColumn('c.company_id', 'billing_options.company_id')
                    ->whereRaw($match), 'customers') : $query)
            ->defaultSort('name')
            ->paginated([25, 50, 100, 'all'])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('option_id')->label('বিলিং ID')->sortable()->searchable(),
                TextColumn::make('name')->label(BillingOption::KINDS[static::$kind])->sortable()->searchable()->weight('medium')->wrap(),
                ...static::columns(),
                ...($match ? [TextColumn::make('customers')->label('কাস্টমার')->numeric()->sortable()
                    ->tooltip('বর্তমান কাস্টমার (Left আর বিলিংয়ে নেই বাদে), ডেস্কের কাস্টমার কপি থেকে')] : []),
                ...(in_array(static::$kind, self::SWITCH_KINDS, true) ? [IconColumn::make('billing_enabled')->label('বিলিংয়ে চালু')->boolean()->placeholder('—')] : []),
                TextColumn::make('active')->label('বিলিংয়ে')->badge()
                    ->state(fn (BillingOption $r) => $r->active ? 'আছে' : 'নেই')
                    ->color(fn (string $state) => $state === 'আছে' ? 'success' : 'gray')
                    ->tooltip(fn (BillingOption $r) => $r->active ? null : 'শেষ Sync-এ বিলিংয়ে পাওয়া যায়নি'),
                TextColumn::make('synced_at')->label('শেষ Sync')->since()->dateTimeTooltip('d M Y, g:i A', 'Asia/Dhaka')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('active')->label('বিলিংয়ে')->trueLabel('আছে')->falseLabel('নেই')->default(true),
            ]);
    }
}
