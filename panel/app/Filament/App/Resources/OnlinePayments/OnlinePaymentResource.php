<?php

namespace App\Filament\App\Resources\OnlinePayments;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\DailyBills\DailyBillResource;
use App\Filament\App\Resources\OnlinePayments\Pages\ListOnlinePayments;
use App\Models\BillingCustomer;
use App\Models\BillingOnlinePayment;
use App\Support\Menu;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Online payments (bKash/Nagad webhook and bKash PayBill) of the billing software, synced every 3 minutes. Billing
 * settles them by itself when the reference is a customer code; "সেটেল হয়নি" ones wait for staff in billing.
 */
class OnlinePaymentResource extends Resource
{
    protected static ?string $model = BillingOnlinePayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $slug = 'online-payments';

    protected static string|\UnitEnum|null $navigationGroup = 'কাস্টমার ও বিল';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('অনলাইন পেমেন্ট');
    }

    public static function getModelLabel(): string
    {
        return __('অনলাইন পেমেন্ট');
    }

    public static function getPluralModelLabel(): string
    {
        return __('অনলাইন পেমেন্ট');
    }

    public static function canAccess(): bool
    {
        return Menu::can('bills');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Unsettled ones of the last 7 days, as a badge. */
    public static function getNavigationBadge(): ?string
    {
        $n = BillingOnlinePayment::where('company_id', Filament::getTenant()?->getKey() ?? 0)->where('status_id', 1)
            ->where('paid_at', '>', now()->subDays(7))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return $table
            ->defaultSort('paid_at', 'desc')
            ->poll('60s')
            ->paginated([25, 50, 100])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('paid_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('gateway')->label(__('মাধ্যম'))->badge()
                    ->color(fn (?string $state) => match (strtolower((string) $state)) { 'bkash' => 'danger', 'nagad' => 'warning', default => 'gray' })
                    ->description(fn (BillingOnlinePayment $r) => BillingOnlinePayment::SOURCES[$r->source] ?? $r->source),
                TextColumn::make('trx_id')->label('TrxID')->copyable()->searchable()->placeholder('—'),
                TextColumn::make('amount')->label(__('টাকা'))->formatStateUsing(fn ($state) => $tk($state))->sortable(),
                TextColumn::make('customer_name')->label(__('কাস্টমার'))
                    ->state(fn (BillingOnlinePayment $r) => $r->customer_name ?: ($r->customer_id ? 'ID '.$r->customer_id : null))
                    ->description(fn (BillingOnlinePayment $r) => $r->customer_id ? trim('ID '.$r->customer_id.($r->customer_username ? ' · '.$r->customer_username : '')) : null)
                    ->url(fn (BillingOnlinePayment $r) => $r->customer_row && Menu::can('customers') ? CustomerResource::getUrl('view', ['record' => $r->customer_row]) : null)
                    ->placeholder(__('চেনা যায়নি')),
                TextColumn::make('reference')->label(__('রেফারেন্স'))->placeholder('—')->searchable(),
                TextColumn::make('payer')->label(__('যে নম্বর থেকে'))->placeholder('—')->searchable()->copyable(),
                TextColumn::make('status_id')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (BillingOnlinePayment $record) => $record->statusLabel())
                    ->color(fn (BillingOnlinePayment $record) => $record->statusColor())
                    ->tooltip(fn (BillingOnlinePayment $r) => $r->updated_by ? __('করেছেন: :u', ['u' => $r->updated_by]) : null),
                TextColumn::make('updated_by')->label(__('কে সেটেল করলেন'))->placeholder('—')->toggleable(),
                TextColumn::make('note')->label(__('নোট'))->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trx_status')->label(__('গেটওয়ের অবস্থা'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('month')->label(__('মাস'))->options(fn () => DailyBillResource::months())->default(now('Asia/Dhaka')->format('Y-m'))
                    ->query(function (Builder $query, array $data) {
                        if (! ($m = $data['value'] ?? null)) {
                            return $query;
                        }
                        $start = Carbon::parse($m.'-01', 'Asia/Dhaka')->startOfMonth();

                        return $query->where('paid_at', '>=', $start->copy()->utc())->where('paid_at', '<', $start->copy()->addMonth()->utc());
                    }),
                Filter::make('date')->label(__('তারিখ'))
                    ->schema([DatePicker::make('from')->label(__('তারিখ থেকে')), DatePicker::make('until')->label(__('তারিখ পর্যন্ত'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('paid_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('paid_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? __('থেকে :date', ['date' => Carbon::parse($data['from'])->format('d M Y')]) : null,
                        ($data['until'] ?? null) ? __('পর্যন্ত :date', ['date' => Carbon::parse($data['until'])->format('d M Y')]) : null]))),
                SelectFilter::make('status')->label(__('অবস্থা'))->attribute('status_id')
                    ->options(collect(BillingOnlinePayment::STATUSES)->map(fn ($s) => __($s[0]))->all()),
                SelectFilter::make('source')->label(__('কোথা থেকে'))->options(BillingOnlinePayment::SOURCES),
                SelectFilter::make('gateway')->label(__('মাধ্যম'))
                    ->options(fn () => BillingOnlinePayment::where('company_id', $company())->whereNotNull('gateway')
                        ->distinct()->orderBy('gateway')->pluck('gateway', 'gateway')->all()),
                TernaryFilter::make('matched')->label(__('কাস্টমার'))
                    ->trueLabel(__('চেনা'))->falseLabel(__('চেনা যায়নি'))
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('billing_online_payments.customer_header_id'),
                        false: fn (Builder $q) => $q->whereNull('billing_online_payments.customer_header_id')),
                SelectFilter::make('updated_by')->label(__('কে সেটেল করলেন'))->multiple()
                    ->options(fn () => BillingOnlinePayment::where('company_id', $company())->whereNotNull('updated_by')
                        ->distinct()->orderBy('updated_by')->pluck('updated_by', 'updated_by')->all()),
            ])
            ->filtersFormColumns(3);
    }

    public static function getEloquentQuery(): Builder
    {
        $customer = fn (string $col) => BillingCustomer::select($col)
            ->whereColumn('billing_customers.company_id', 'billing_online_payments.company_id')
            ->whereColumn('billing_customers.header_id', 'billing_online_payments.customer_header_id')->limit(1);

        return parent::getEloquentQuery()->select('billing_online_payments.*')
            ->addSelect(['customer_row' => $customer('id'), 'customer_name' => $customer('name'), 'customer_username' => $customer('username')])
            ->where('billing_online_payments.company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListOnlinePayments::route('/')];
    }
}
