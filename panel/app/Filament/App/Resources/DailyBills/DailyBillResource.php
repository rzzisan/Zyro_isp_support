<?php

namespace App\Filament\App\Resources\DailyBills;

use App\Filament\App\Resources\DailyBills\Pages\ListDailyBills;
use App\Models\BillCollection;
use App\Models\BillingDailyBill;
use App\Models\Membership;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every bill collected in the billing software (Daily Bill Collection, synced every 10 minutes). Owner/admin see
 * all; an agent sees the collectors picked for them on the team page plus what they entered themselves.
 */
class DailyBillResource extends Resource
{
    protected static ?string $model = BillingDailyBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'বিল কালেকশন';

    protected static ?string $modelLabel = 'বিল';

    protected static ?string $pluralModelLabel = 'বিল কালেকশন';

    protected static ?string $slug = 'daily-bills';

    protected static ?int $navigationSort = -5;

    public const STATUSES = ['active' => 'approve + pending', 'approved' => 'Approved', 'pending' => 'Pending (approve বাকি)', 'deleted' => 'Deleted'];

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('bills');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function membership(): ?Membership
    {
        return Membership::where('company_id', Filament::getTenant()?->getKey() ?? 0)->where('user_id', auth()->id())->first();
    }

    /** Local (Bangladesh) time of collected_at, for SQL. */
    private const LOCAL = "((billing_daily_bills.collected_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Dhaka')";

    /** The last 12 months as ['2026-10' => 'October 2026']. */
    public static function months(): array
    {
        $now = now('Asia/Dhaka')->startOfMonth();

        return collect(range(0, 11))->mapWithKeys(fn ($i) => [$now->copy()->subMonths($i)->format('Y-m') => $now->copy()->subMonths($i)->format('F Y')])->all();
    }

    /** Collectors this member may filter by: [emp_header_id => name]. */
    public static function collectorOptions(): array
    {
        $company = Filament::getTenant()?->getKey() ?? 0;
        $all = BillingDailyBill::collectors($company);
        $m = static::membership();
        if ($m && ! in_array($m->role, ['owner', 'admin'], true)) {
            $visible = BillingDailyBill::where('company_id', $company)->visibleTo($m)->distinct()->pluck('emp_header_id')->filter()->all();
            $all = array_intersect_key($all, array_flip($visible));
        }

        return $all;
    }

    public static function table(Table $table): Table
    {
        $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return $table
            ->defaultSort('collected_at', 'desc')
            ->paginated([25, 50, 100])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('collected_at')->label('সময়')->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('customer_name')->label('কাস্টমার')
                    ->description(fn (BillingDailyBill $r) => trim('ID '.$r->customer_id.($r->username ? ' · '.$r->username : '')))
                    ->searchable(['customer_name', 'customer_id', 'username', 'mobile']),
                TextColumn::make('receive_amount')->label('টাকা')->formatStateUsing(fn ($state) => $tk($state))->sortable(),
                TextColumn::make('discount_amount')->label('ডিসকাউন্ট')->formatStateUsing(fn ($state) => $tk($state))->toggleable(),
                TextColumn::make('balance_due')->label('বকেয়া')->formatStateUsing(fn ($state) => $tk($state))->toggleable(),
                TextColumn::make('payment_method')->label('মাধ্যম')->placeholder('—'),
                TextColumn::make('received_by')->label('Received By')->placeholder('—')->sortable(),
                TextColumn::make('created_by')->label('কে এন্ট্রি দিলেন')->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->state(fn (BillingDailyBill $r) => $r->status())
                    ->formatStateUsing(fn (string $state) => ['approved' => 'Approved', 'pending' => 'approve বাকি', 'deleted' => 'Deleted'][$state])
                    ->color(fn (string $state) => ['approved' => 'success', 'pending' => 'warning', 'deleted' => 'danger'][$state])
                    ->tooltip(fn (BillingDailyBill $r) => $r->approved_by ? 'Approved by '.$r->approved_by : null),
                TextColumn::make('mobile')->label('মোবাইল')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')->label('নোট')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('receipt')->label('রিসিট')->icon(Heroicon::OutlinedPrinter)
                    ->visible(fn (BillingDailyBill $record) => ! $record->is_deleted)
                    ->url(fn (BillingDailyBill $record) => route('bills.receipt', ['company' => Filament::getTenant()->slug, 'dailyBill' => $record->daily_bill_id]))
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('month')->label('মাস')->options(fn () => static::months())->default(now('Asia/Dhaka')->format('Y-m'))
                    ->query(function (Builder $query, array $data) {
                        if (! ($m = $data['value'] ?? null)) {
                            return $query;
                        }
                        $start = Carbon::parse($m.'-01', 'Asia/Dhaka')->startOfMonth();

                        return $query->where('collected_at', '>=', $start->copy()->utc())->where('collected_at', '<', $start->copy()->addMonth()->utc());
                    }),
                Filter::make('date')->label('তারিখ')
                    ->schema([DatePicker::make('from')->label('তারিখ থেকে'), DatePicker::make('until')->label('তারিখ পর্যন্ত')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('collected_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('collected_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? 'থেকে '.Carbon::parse($data['from'])->format('d M Y') : null,
                        ($data['until'] ?? null) ? 'পর্যন্ত '.Carbon::parse($data['until'])->format('d M Y') : null]))),
                Filter::make('time')->label('সময়')
                    ->schema([TimePicker::make('from')->label('সময় থেকে')->seconds(false), TimePicker::make('until')->label('সময় পর্যন্ত')->seconds(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $t) => $q->whereRaw(self::LOCAL.'::time >= ?::time', [$t]))
                        ->when($data['until'] ?? null, fn ($q, $t) => $q->whereRaw(self::LOCAL.'::time <= ?::time', [$t])))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? 'সময় '.substr($data['from'], 0, 5).' থেকে' : null,
                        ($data['until'] ?? null) ? 'সময় '.substr($data['until'], 0, 5).' পর্যন্ত' : null]))),
                SelectFilter::make('emp_header_id')->label('Received By (ইউজার)')->multiple()->options(fn () => static::collectorOptions()),
                SelectFilter::make('created_by')->label('কে এন্ট্রি দিলেন')->multiple()
                    ->options(fn () => BillingDailyBill::where('company_id', $company())->visibleTo(static::membership())
                        ->whereNotNull('created_by')->distinct()->orderBy('created_by')->pluck('created_by', 'created_by')->all()),
                SelectFilter::make('status')->label('অবস্থা')->options(self::STATUSES)->default('active')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'active') {
                        'approved' => $query->where('is_deleted', false)->where('is_approved', true),
                        'pending' => $query->where('is_deleted', false)->where('is_approved', false),
                        'deleted' => $query->where('is_deleted', true),
                        default => $query->where('is_deleted', false),
                    }),
                SelectFilter::make('payment_method')->label('মাধ্যম')
                    ->options(fn () => BillingDailyBill::where('company_id', $company())->whereNotNull('payment_method')
                        ->distinct()->orderBy('payment_method')->pluck('payment_method', 'payment_method')->all()),
            ])
            ->filtersFormColumns(3);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select('billing_daily_bills.*')
            ->addSelect(['desk_collection_id' => BillCollection::select('id')
                ->whereColumn('bill_collections.company_id', 'billing_daily_bills.company_id')
                ->whereColumn('bill_collections.daily_bill_id', 'billing_daily_bills.daily_bill_id')
                ->where('result', 'saved')->limit(1)])
            ->where('billing_daily_bills.company_id', Filament::getTenant()?->getKey() ?? 0)
            ->visibleTo(static::membership());
    }

    public static function getPages(): array
    {
        return ['index' => ListDailyBills::route('/')];
    }
}
