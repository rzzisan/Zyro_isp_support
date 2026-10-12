<?php

namespace App\Filament\App\Resources\Customers;

use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillingCustomer;
use App\Models\BillingOption;
use App\Models\PppSession;
use App\Services\Engine;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** All billing customers from our own copy (synced nightly). Everyone can look; passwords and CSV are owner/admin. */
class CustomerResource extends Resource
{
    protected static ?string $model = BillingCustomer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'কাস্টমার';

    protected static ?string $modelLabel = 'কাস্টমার';

    protected static ?string $pluralModelLabel = 'কাস্টমার';

    protected static ?string $slug = 'customers';

    protected static string|\UnitEnum|null $navigationGroup = 'কাস্টমার ও বিল';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Menu::can('customers');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function managers(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    private static function options(string $column): array
    {
        return BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')->where('is_left', false)
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all();
    }

    /** A billing list kept in the desk (বিলিং কনফিগারেশন) plus any other value our customers have. */
    private static function listOptions(string $kind, string $column): array
    {
        return BillingOption::names(Filament::getTenant()?->getKey() ?? 0, $kind, static::options($column));
    }

    /** Subquery: a PPPoE session for this customer seen in the last few minutes. */
    public static function freshSession(): \Closure
    {
        return fn ($q) => $q->from('ppp_sessions')->whereColumn('ppp_sessions.company_id', 'billing_customers.company_id')
            ->whereColumn('ppp_sessions.username', 'billing_customers.username')
            ->where('ppp_sessions.seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('customer_id')
            ->modifyQueryUsing(fn (Builder $query) => $query->select('billing_customers.*')->addSelect(['online_uptime' => PppSession::query()->select('uptime')->whereColumn('ppp_sessions.company_id', 'billing_customers.company_id')
                ->whereColumn('ppp_sessions.username', 'billing_customers.username')
                ->where('seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))->limit(1)]))
            ->striped()
            ->columns([
                TextColumn::make('customer_id')->label('ID')->sortable()->searchable(query: fn (Builder $query, string $search) => $query
                    ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                        ->orWhere('name', 'ilike', "%{$search}%")->orWhere('username', 'ilike', "%{$search}%")
                        ->orWhere('mobile', 'like', '%'.preg_replace('/\D/', '', $search).'%'))),
                TextColumn::make('name')->label('কাস্টমার')->description(fn (BillingCustomer $r) => $r->mobile)->wrap(),
                TextColumn::make('username')->label('PPPoE ID')->copyable()->toggleable(),
                TextColumn::make('zone')->label('Zone')->description(fn (BillingCustomer $r) => trim($r->subzone.($r->box ? ' · '.$r->box : '')))->toggleable(),
                TextColumn::make('package')->label('প্যাকেজ')->toggleable(),
                TextColumn::make('monthly_bill')->label('মাসিক বিল')->numeric(0)->sortable(),
                TextColumn::make('due')->label('বকেয়া')->numeric(0)->sortable()->placeholder('—')
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('online')->label('এখন')->badge()
                    ->state(fn (BillingCustomer $r) => $r->online_uptime !== null ? 'অনলাইন' : 'অফলাইন')
                    ->description(fn (BillingCustomer $r) => $r->online_uptime)
                    ->color(fn (string $state) => $state === 'অনলাইন' ? 'success' : 'gray'),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->state(fn (BillingCustomer $r) => $r->gone_at ? 'বিলিংয়ে নেই' : ($r->is_left ? 'Left' : ($r->disabled ? 'বন্ধ' : ($r->status ?: '—'))))
                    ->description(fn (BillingCustomer $r) => $r->is_left && $r->left_on ? $r->left_on->format('d M Y') : null)
                    ->color(fn (string $state) => match ($state) {
                        'Active' => 'success', 'বন্ধ' => 'danger', 'Left' => 'warning', default => 'gray'
                    }),
                TextColumn::make('bill_day')->label('বিলের তারিখ')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('joined_on')->label('যোগ দিয়েছেন')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::listOptions('zones', 'zone'))->searchable(),
                SelectFilter::make('subzone')->label('Subzone')->options(fn () => static::listOptions('subzones', 'subzone'))->searchable(),
                SelectFilter::make('box')->label('Box')->options(fn () => static::listOptions('boxes', 'box'))->searchable(),
                SelectFilter::make('package')->label('প্যাকেজ')->options(fn () => static::listOptions('packages', 'package'))->searchable(),
                SelectFilter::make('connection_type')->label('Connection Type')->options(fn () => static::listOptions('connection_types', 'connection_type')),
                SelectFilter::make('status')->label('অবস্থা')->options(fn () => static::options('status')),
                TernaryFilter::make('online')->label('এখন')->trueLabel('অনলাইন')->falseLabel('অফলাইন')
                    ->queries(true: fn (Builder $query) => $query->whereExists(static::freshSession()),
                        false: fn (Builder $query) => $query->whereNotExists(static::freshSession())),
                TernaryFilter::make('disabled')->label('লাইন')->trueLabel('বন্ধ')->falseLabel('চালু'),
                TernaryFilter::make('has_due')->label('বকেয়া')->trueLabel('বকেয়া আছে')->falseLabel('বকেয়া নেই')
                    ->queries(true: fn (Builder $query) => $query->where('due', '>', 0),
                        false: fn (Builder $query) => $query->where(fn ($w) => $w->whereNull('due')->orWhere('due', '<=', 0))),
                SelectFilter::make('list')->label('তালিকা')->default('current')->selectablePlaceholder(false)
                    ->options(['current' => 'বর্তমান কাস্টমার', 'left' => 'Left কাস্টমার', 'gone' => 'বিলিংয়ে নেই', 'all' => 'সব'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'current') {
                        'left' => $query->whereNull('gone_at')->where('is_left', true),
                        'gone' => $query->whereNotNull('gone_at'),
                        'all' => $query,
                        default => $query->whereNull('gone_at')->where('is_left', false),
                    }),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->recordActions([ViewAction::make()->label('দেখুন')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // identity, connection and bill as cards, the same look as the live MikroTik / OLT box above; below them
        // billing's customer details tabs, in billing's order (docs/customer-profile-plan.md)
        $info = fn (string $tab) => View::make('filament.app.customers.info-tab')->viewData(['tab' => $tab]);
        $list = fn (string $class) => Livewire::make($class)->lazy();
        $tab = fn (string $label, string $icon) => Tab::make($label)->icon($icon);

        return $schema->components([
            View::make('filament.app.customers.profile-cards')->columnSpanFull(),
            Tabs::make('history')->columnSpanFull()->persistTabInQueryString()->tabs([
                $tab('সার্ভিস', 'heroicon-o-wifi')->schema([$info('service')]),
                $tab('নেটওয়ার্ক ও ডিভাইস', 'heroicon-o-server-stack')->schema([$info('network')]),
                $tab('ব্যক্তিগত তথ্য', 'heroicon-o-identification')->schema([$info('personal')]),
                $tab('বিল', 'heroicon-o-document-text')->schema([$list(History\BillsTable::class)])->visible(fn () => Menu::can('bills')),
                $tab('পেমেন্ট', 'heroicon-o-banknotes')->schema([$list(History\PaymentsTable::class)])->visible(fn () => Menu::can('bills')),
                $tab('অভিযোগ', 'heroicon-o-lifebuoy')->schema([$list(History\ComplainsTable::class)]),
                $tab('মন্তব্য', 'heroicon-o-chat-bubble-bottom-center-text')->schema([$list(History\RemarksTable::class)]),
                $tab('SMS', 'heroicon-o-envelope')->schema([$list(History\SmsTable::class)]),
                $tab('পরিবর্তনের ইতিহাস', 'heroicon-o-arrows-right-left')->schema([$list(History\ChangesTable::class)]),
                $tab('লাইন চালু/বন্ধ', 'heroicon-o-power')->schema([$list(History\LineLogsTable::class)]),
                $tab('ইনভয়েস', 'heroicon-o-receipt-percent')->schema([$list(History\InvoicesTable::class)]),
            ]),
        ]);
    }

    /**
     * Header search, like the billing software's: any part of the customer ID, PPPoE user, name or mobile. Reads the
     * desk's own copy; when that has nobody, asks billing's header search and copies the customers we don't have.
     * Left customers are included (marked) after the current ones.
     */
    public static function getGlobalSearchResults(string $search): Collection
    {
        $q = trim(mb_substr($search, 0, 30));
        if ($q === '' || ! ($company = Filament::getTenant())) {
            return collect();
        }
        $rows = static::globalSearchQuery()->where(function (Builder $w) use ($q) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$q])->orWhere('customer_id', 'ilike', $like)
                ->orWhere('username', 'ilike', $like)->orWhere('name', 'ilike', $like);
            $digits = ltrim(preg_replace('/\D/', '', $q), '0');
            if (strlen($digits) >= 4 && strlen($digits) * 2 >= strlen($q)) {
                $w->orWhere('mobile_normalized', 'like', "%{$digits}%");
            }
        })
            ->orderByRaw("(ltrim(customer_id, '0') = ltrim(?, '0') OR lower(username) = lower(?)) DESC", [$q, $q])
            ->orderBy('is_left')->orderByRaw("CASE WHEN customer_id ~ '^[0-9]{1,9}$' THEN customer_id::bigint END")
            ->limit(30)->get();

        if ($rows->isEmpty() && mb_strlen($q) >= 3) {
            $ids = Cache::remember('header-search:'.$company->getKey().':'.mb_strtolower($q), 300,
                fn () => Engine::findInBilling($company->getKey(), $q));
            $rows = $ids ? static::globalSearchQuery()->whereIn('header_id', $ids)->orderBy('is_left')->limit(30)->get() : $rows;
        }

        return $rows->map(fn (BillingCustomer $r) => new GlobalSearchResult(
            title: trim(($r->username ?: $r->name).' ('.$r->customer_id.')'),
            url: static::getUrl('view', ['record' => $r]),
            details: array_filter([
                'নাম' => $r->name,
                'মোবাইল' => $r->mobile,
                'Zone' => collect([$r->zone, $r->subzone, $r->box])->filter()->join(' / ') ?: null,
                'অবস্থা' => $r->is_left ? 'Left'.($r->left_on ? ' ('.$r->left_on->format('d M Y').')' : '')
                    : ($r->disabled ? 'লাইন বন্ধ' : ($r->status ?: null)),
                'বকেয়া' => $r->due > 0 ? number_format((float) $r->due).' টাকা' : null,
            ]),
        ));
    }

    private static function globalSearchQuery(): Builder
    {
        return static::getEloquentQuery()->whereNull('gone_at');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomers::route('/'), 'view' => ViewCustomer::route('/{record}')];
    }
}
