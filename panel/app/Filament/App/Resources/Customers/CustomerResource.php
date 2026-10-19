<?php

namespace App\Filament\App\Resources\Customers;

use App\Filament\App\BillActions;
use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillingCustomer;
use App\Models\BillingOption;
use App\Models\PppSession;
use App\Services\Engine;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/** All billing customers from our own copy (synced nightly). Everyone can look; passwords and CSV are owner/admin. */
class CustomerResource extends Resource
{
    protected static ?string $model = BillingCustomer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getNavigationLabel(): string
    {
        return __('কাস্টমার');
    }

    public static function getModelLabel(): string
    {
        return __('কাস্টমার');
    }

    public static function getPluralModelLabel(): string
    {
        return __('কাস্টমার');
    }

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

    /** Line on/off from the list: the member needs the line_switch permission; Left / gone customers can't be switched. */
    public static function canSwitch(BillingCustomer $r): bool
    {
        return ! $r->gone_at && ! $r->is_left && Menu::can('line_switch');
    }

    /** This month's VAT from the billing row, when billing sends one. */
    public static function vat(BillingCustomer $r): ?float
    {
        foreach (['VAT', 'Vat', 'VatAmount', 'VATAmount'] as $k) {
            if (is_numeric($v = str_replace(',', '', (string) ($r->extra[$k] ?? '')))) {
                return (float) $v;
            }
        }

        return null;
    }

    /** Subquery: a PPPoE session for this customer seen in the last few minutes, with internet (not on a block profile). */
    public static function freshSession(bool $blocked = false): \Closure
    {
        return fn ($q) => $q->from('ppp_sessions')->whereColumn('ppp_sessions.company_id', 'billing_customers.company_id')
            ->whereColumn('ppp_sessions.username', 'billing_customers.username')
            ->where('ppp_sessions.seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))
            ->where('ppp_sessions.blocked', $blocked);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('customer_id')
            ->modifyQueryUsing(fn (Builder $query) => $query->select('billing_customers.*')->addSelect([
                'online_uptime' => PppSession::query()->select('uptime')->whereColumn('ppp_sessions.company_id', 'billing_customers.company_id')
                    ->whereColumn('ppp_sessions.username', 'billing_customers.username')
                    ->where('seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))->where('blocked', false)->limit(1),
                // connected on a block profile: billing disabled the line, no internet
                'online_blocked' => PppSession::query()->select('profile')->whereColumn('ppp_sessions.company_id', 'billing_customers.company_id')
                    ->whereColumn('ppp_sessions.username', 'billing_customers.username')
                    ->where('seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))->where('blocked', true)->limit(1),
            ]))
            ->striped()
            // same columns, in the same order, as billing's customer list, so staff find things where they expect them
            // compact rows (public/css/zyro-theme.css .z-cc) so the whole list fits without scrolling sideways
            ->columns(array_map(fn ($c) => $c->extraCellAttributes(['class' => 'z-cc'])->extraHeaderAttributes(['class' => 'z-cc']), [
                TextColumn::make('customer_id')->label('C.Code')->sortable()->searchable(query: fn (Builder $query, string $search) => $query
                    ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                        ->orWhere('name', 'ilike', "%{$search}%")->orWhere('username', 'ilike', "%{$search}%")
                        ->orWhere('mobile', 'like', '%'.preg_replace('/\D/', '', $search).'%'))),
                TextColumn::make('username')->label('ID/IP')->copyable()->placeholder('—')
                    ->icon(Heroicon::Signal)->iconColor(fn (BillingCustomer $r) => $r->online_uptime !== null ? 'success' : ($r->online_blocked !== null ? 'danger' : 'gray'))
                    ->tooltip(fn (BillingCustomer $r) => $r->online_uptime !== null ? __('অনলাইন · ').$r->online_uptime
                        : ($r->online_blocked !== null ? __('ব্লক · ইন্টারনেট নেই (:profile)', ['profile' => $r->online_blocked]) : __('অফলাইন'))),
                TextColumn::make('name')->label('Cus.Name / Mobile')->wrap()->description(fn (BillingCustomer $r) => $r->mobile),
                TextColumn::make('zone')->label('Zone')->sortable()->placeholder('—')
                    ->description(fn (BillingCustomer $r) => trim($r->subzone.($r->box ? ' · '.$r->box : '')) ?: null),
                TextColumn::make('customer_type')->label('Cus.Type')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('connection_type')->label('Conn.Type')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('package')->label('Package')->placeholder('—'),
                TextColumn::make('speed')->label('Speed')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bill_day')->label('Ex.Date')->sortable()->placeholder('—')->alignCenter(),
                TextColumn::make('monthly_bill')->label('M.Bill')->numeric(0)->sortable()->alignEnd(),
                TextColumn::make('paid')->label('Received')->numeric(0)->alignEnd()
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format((float) $state) : '—'),
                TextColumn::make('vat')->label('VAT')->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (BillingCustomer $r) => number_format((float) (static::vat($r) ?? 0))),
                TextColumn::make('due')->label('Due')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format((float) $state) : '—')
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('advance')->label('Advance')->alignEnd()->toggleable()
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format((float) $state) : '—'),
                TextColumn::make('last_payment_date')->label('Pay Date')->date('d M y')->sortable()->placeholder('—'),
                TextColumn::make('server')->label('Server')->placeholder('—')->toggleable(),
                ViewColumn::make('line')->label('M.Status')->view('filament.app.customers.line-switch')
                    ->disabledClick(fn (BillingCustomer $r) => ! static::canSwitch($r))
                    ->action(Action::make('switchLine')->requiresConfirmation()
                        ->modalHeading(fn (BillingCustomer $record) => "{$record->name} (ID {$record->customer_id}): ".($record->disabled ? __('লাইন চালু করবেন?') : __('লাইন বন্ধ করবেন?')))
                        ->modalDescription(fn (BillingCustomer $record) => $record->disabled
                            ? __('বিলিং-এ লাইন Enable হবে।').($record->due > 0 ? ' '.__('বকেয়া :amount টাকা।', ['amount' => number_format((float) $record->due)]) : '')
                            : __('বিলিং-এ লাইন Disable হবে, কাস্টমারের ইন্টারনেট বন্ধ হয়ে যাবে।'))
                        ->modalSubmitActionLabel(fn (BillingCustomer $record) => $record->disabled ? __('চালু করুন') : __('বন্ধ করুন'))
                        ->color(fn (BillingCustomer $record) => $record->disabled ? 'success' : 'danger')
                        ->action(function (BillingCustomer $record) {
                            if (! static::canSwitch($record)) {
                                return;
                            }
                            $on = (bool) $record->disabled;
                            try {
                                Engine::switchLine((int) $record->company_id, (int) $record->header_id, $on);
                            } catch (RuntimeException $e) {
                                Notification::make()->danger()->title($on ? __('লাইন চালু হয়নি') : __('লাইন বন্ধ হয়নি'))->body($e->getMessage())->persistent()->send();

                                return;
                            }
                            $record->refresh();
                            Notification::make()->success()->title("{$record->name}: ".($on ? __('লাইন চালু করা হয়েছে') : __('লাইন বন্ধ করা হয়েছে')))->send();
                        })),
                TextColumn::make('bill_status')->label('B.Status')->badge()
                    ->state(fn (BillingCustomer $r) => $r->due > 0 ? 'Due' : 'Paid')
                    ->color(fn (string $state) => $state === 'Due' ? 'danger' : 'success'),
                TextColumn::make('joined_on')->label(__('যোগ দিয়েছেন'))->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ]))
            ->filters([
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::listOptions('zones', 'zone'))->searchable(),
                SelectFilter::make('subzone')->label('Subzone')->options(fn () => static::listOptions('subzones', 'subzone'))->searchable(),
                SelectFilter::make('box')->label('Box')->options(fn () => static::listOptions('boxes', 'box'))->searchable(),
                SelectFilter::make('package')->label(__('প্যাকেজ'))->options(fn () => static::listOptions('packages', 'package'))->searchable(),
                SelectFilter::make('connection_type')->label('Connection Type')->options(fn () => static::listOptions('connection_types', 'connection_type')),
                SelectFilter::make('status')->label(__('অবস্থা'))->options(fn () => static::options('status')),
                TernaryFilter::make('online')->label(__('এখন'))->trueLabel(__('অনলাইন'))->falseLabel(__('অফলাইন'))
                    ->queries(true: fn (Builder $query) => $query->whereExists(static::freshSession()),
                        false: fn (Builder $query) => $query->whereNotExists(static::freshSession())),
                TernaryFilter::make('disabled')->label(__('লাইন'))->trueLabel(__('বন্ধ'))->falseLabel(__('চালু')),
                TernaryFilter::make('has_due')->label(__('বকেয়া'))->trueLabel(__('বকেয়া আছে'))->falseLabel(__('বকেয়া নেই'))
                    ->queries(true: fn (Builder $query) => $query->where('due', '>', 0),
                        false: fn (Builder $query) => $query->where(fn ($w) => $w->whereNull('due')->orWhere('due', '<=', 0))),
                SelectFilter::make('list')->label(__('তালিকা'))->default('current')->selectablePlaceholder(false)
                    ->options(['current' => __('বর্তমান কাস্টমার'), 'left' => __('Left কাস্টমার'), 'gone' => __('বিলিংয়ে নেই'), 'all' => __('সব')])
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
            ->recordActions([
                BillActions::receive(forRecord: true)->label('Pay')->button()->size('sm')->color('gray')
                    ->visible(fn (BillingCustomer $record) => $record->due > 0 && ! $record->gone_at && Menu::can('bills')),
                ViewAction::make()->label(__('দেখুন'))->iconButton(),
            ]);
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
                $tab(__('সার্ভিস'), 'heroicon-o-wifi')->schema([$info('service')]),
                $tab(__('নেটওয়ার্ক ও ডিভাইস'), 'heroicon-o-server-stack')->schema([$info('network')]),
                $tab(__('ব্যক্তিগত তথ্য'), 'heroicon-o-identification')->schema([$info('personal')]),
                $tab(__('বিল'), 'heroicon-o-document-text')->schema([$list(History\BillsTable::class)])->visible(fn () => Menu::can('bills')),
                $tab(__('পেমেন্ট'), 'heroicon-o-banknotes')->schema([$list(History\PaymentsTable::class)])->visible(fn () => Menu::can('bills')),
                $tab(__('অভিযোগ'), 'heroicon-o-lifebuoy')->schema([$list(History\ComplainsTable::class)]),
                $tab(__('মন্তব্য'), 'heroicon-o-chat-bubble-bottom-center-text')->schema([$list(History\RemarksTable::class)]),
                $tab('SMS', 'heroicon-o-envelope')->schema([$list(History\SmsTable::class)]),
                $tab(__('পরিবর্তনের ইতিহাস'), 'heroicon-o-arrows-right-left')->schema([$list(History\ChangesTable::class)]),
                $tab(__('লাইন চালু/বন্ধ'), 'heroicon-o-power')->schema([$list(History\LineLogsTable::class)]),
                $tab(__('ইনভয়েস'), 'heroicon-o-receipt-percent')->schema([$list(History\InvoicesTable::class)]),
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
        if (! ($company = Filament::getTenant())) {
            return collect();
        }
        $rows = BillingCustomer::headerSearch((int) $company->getKey(), $search);

        return $rows->map(fn (BillingCustomer $r) => new GlobalSearchResult(
            title: trim(($r->username ?: $r->name).' ('.$r->customer_id.')'),
            url: static::getUrl('view', ['record' => $r]),
            details: array_filter([
                __('নাম') => $r->name,
                __('মোবাইল') => $r->mobile,
                'Zone' => collect([$r->zone, $r->subzone, $r->box])->filter()->join(' / ') ?: null,
                __('অবস্থা') => $r->is_left ? 'Left'.($r->left_on ? ' ('.$r->left_on->format('d M Y').')' : '')
                    : ($r->disabled ? __('লাইন বন্ধ') : ($r->status ?: null)),
                __('বকেয়া') => $r->due > 0 ? number_format((float) $r->due).__(' টাকা') : null,
            ]),
        ));
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
