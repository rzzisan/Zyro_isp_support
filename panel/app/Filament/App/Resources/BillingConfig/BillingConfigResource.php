<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Models\BillingConnection;
use App\Models\BillingOption;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
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
 * only by the page's Sync button. The ticket lists (কর্মী, ডিপার্টমেন্ট, সমস্যার ধরন) become the Desk's own once the
 * বিলিং সংযোগ page's ticket-lists switch is off: then they are added, renamed and switched off here, never synced. Each list is a small subclass, shown as a tab of its cluster menu item
 * (Clusters/BillingConfig: এলাকা, প্যাকেজ ও সংযোগ, সাপোর্ট তালিকা). Owner/Admin only.
 */
abstract class BillingConfigResource extends Resource
{
    /** Lists that have billing's own on/off switch (billing_enabled). */
    private const SWITCH_KINDS = ['zones', 'connection_types', 'client_types', 'protocol_types', 'customer_statuses', 'packages',
        'districts', 'upazilas'];

    /** Lists the Desk can keep itself (Priority stays 1/2/3 = Low/Medium/High). */
    public const DESK_KINDS = ['employees', 'departments', 'categories'];

    /** Desk-made entries get ids from here, far above billing's own. */
    public const DESK_FIRST_ID = 900001;

    /** BillingOption::KINDS key */
    protected static string $kind;

    protected static ?string $model = BillingOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    /** billing_customers column holding this list's name, for the customer count (null = no count). */
    protected static ?string $customerColumn = null;

    public static function kind(): string
    {
        return static::$kind;
    }

    public static function getNavigationLabel(): string
    {
        return __(BillingOption::KINDS[static::$kind]);
    }

    public static function getModelLabel(): string
    {
        return __(BillingOption::KINDS[static::$kind]);
    }

    public static function getPluralModelLabel(): string
    {
        return __(BillingOption::KINDS[static::$kind]);
    }

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return str_replace('_', '-', static::$kind); // under its cluster: /billing-config/area/boxes
    }

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('billing_config') && (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    /** This list is the Desk's own now (added / edited here, no billing Sync). */
    public static function deskOwned(): bool
    {
        return in_array(static::$kind, self::DESK_KINDS, true)
            && BillingConnection::deskTicketLists((int) (Filament::getTenant()?->getKey() ?? 0));
    }

    public static function canCreate(): bool
    {
        return static::deskOwned();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::deskOwned();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;   // old tickets keep the name; switch it off instead
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // a billing entry keeps its name: old tickets, area staff and teams match it by name
            TextInput::make('name')->label(__(BillingOption::KINDS[static::$kind]))->required()->maxLength(255)
                ->disabled(fn (?BillingOption $record) => $record && ! $record->desk)
                ->helperText(fn (?BillingOption $record) => $record && ! $record->desk ? __('বিলিং থেকে আসা নাম বদলানো যায় না (পুরনো টিকিট আর এলাকার কর্মী নাম দিয়ে মেলে); দরকার হলে বন্ধ করে নতুন যোগ করুন') : null),
            Toggle::make('active')->label(__('চালু'))->default(true)
                ->helperText(__('বন্ধ করলে নতুন টিকিটে আর দেখাবে না; পুরনো টিকিটে নাম থাকবে')),
        ])->columns(1);
    }

    /** company, kind and the next Desk id for a new entry. */
    public static function newEntry(array $data): array
    {
        $companyId = (int) Filament::getTenant()->getKey();
        $max = (int) DB::table('billing_options')->where('company_id', $companyId)->where('kind', static::$kind)
            ->whereRaw("option_id ~ '^[0-9]{1,15}$'")->max(DB::raw('option_id::bigint'));

        return [...$data, 'company_id' => $companyId, 'kind' => static::$kind, 'desk' => true,
            'option_id' => (string) max(self::DESK_FIRST_ID, $max + 1)];
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
                TextColumn::make('option_id')->label(__('বিলিং ID'))->sortable()->searchable(),
                TextColumn::make('name')->label(__(BillingOption::KINDS[static::$kind]))->sortable()->searchable()->weight('medium')->wrap(),
                ...static::columns(),
                ...($match ? [TextColumn::make('customers')->label(__('কাস্টমার'))->numeric()->sortable()
                    ->tooltip(__('বর্তমান কাস্টমার (Left আর বিলিংয়ে নেই বাদে), ডেস্কের কাস্টমার কপি থেকে'))] : []),
                ...(in_array(static::$kind, self::SWITCH_KINDS, true) ? [IconColumn::make('billing_enabled')->label(__('বিলিংয়ে চালু'))->boolean()->placeholder('—')] : []),
                ...(static::deskOwned() ? [
                    TextColumn::make('active')->label(__('অবস্থা'))->badge()
                        ->state(fn (BillingOption $r) => $r->active ? __('চালু') : __('বন্ধ'))
                        ->color(fn (string $state) => $state === __('চালু') ? 'success' : 'gray')
                        ->description(fn (BillingOption $r) => $r->desk ? __('Desk-এ যোগ করা') : null),
                ] : [
                    TextColumn::make('active')->label(__('বিলিংয়ে'))->badge()
                        ->state(fn (BillingOption $r) => $r->active ? __('আছে') : __('নেই'))
                        ->color(fn (string $state) => $state === __('আছে') ? 'success' : 'gray')
                        ->tooltip(fn (BillingOption $r) => $r->active ? null : __('শেষ Sync-এ বিলিংয়ে পাওয়া যায়নি')),
                    TextColumn::make('synced_at')->label(__('শেষ Sync'))->since()->dateTimeTooltip('d M Y, g:i A', 'Asia/Dhaka')->toggleable(),
                ]),
            ])
            ->filters([
                static::deskOwned()
                    ? TernaryFilter::make('active')->label(__('অবস্থা'))->trueLabel(__('চালু'))->falseLabel(__('বন্ধ'))->default(true)
                    : TernaryFilter::make('active')->label(__('বিলিংয়ে'))->trueLabel(__('আছে'))->falseLabel(__('নেই'))->default(true),
            ])
            ->headerActions([
                CreateAction::make()->label(__('নতুন যোগ করুন'))->visible(fn () => static::deskOwned())
                    ->mutateDataUsing(fn (array $data) => static::newEntry($data)),
            ])
            ->recordActions([EditAction::make()->visible(fn () => static::deskOwned())]);
    }
}
