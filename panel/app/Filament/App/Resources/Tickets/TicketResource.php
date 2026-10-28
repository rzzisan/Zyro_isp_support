<?php

namespace App\Filament\App\Resources\Tickets;

use App\Filament\App\Resources\Tickets\Pages\ListTickets;
use App\Filament\App\TicketActions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Carbon;
use App\Models\BillingTicket;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Support tickets: the billing software's (synced every 5 minutes; edited, assigned and solved in billing) and, once the
 * বিলিং সংযোগ page sends new tickets to the Desk, the Desk's own (worked in the Desk only).
 */
class TicketResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = BillingTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    public static function getNavigationLabel(): string
    {
        return __('টিকিট');
    }

    public static function getModelLabel(): string
    {
        return __('টিকিট');
    }

    public static function getPluralModelLabel(): string
    {
        return __('টিকিট');
    }

    protected static ?string $slug = 'tickets';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('tickets');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = BillingTicket::where('company_id', Filament::getTenant()?->getKey() ?? 0)
            ->whereIn('state', ['pending', 'processing'])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Distinct values of a column for this company, for filter dropdowns. */
    private static function options(string $column): array
    {
        return BillingTicket::where('company_id', Filament::getTenant()?->getKey() ?? 0)
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all();
    }

    /** A billing list kept in the desk (বিলিং কনফিগারেশন) plus any other value found on tickets (old names too). */
    private static function listOptions(string $kind, string $column): array
    {
        return \App\Models\BillingOption::names(Filament::getTenant()?->getKey() ?? 0, $kind, static::options($column));
    }

    /** Employee names found in "Arif (10-07-26), Rifat" style assigned_to / solved_by values. */
    public static function employees(): array
    {
        $names = [];
        foreach (['assigned_to', 'solved_by'] as $col) {
            foreach (static::options($col) as $v) {
                foreach (explode(',', $v) as $part) {
                    $n = trim(preg_replace('/\s*\(.*?\)\s*/u', '', $part));
                    if ($n !== '') {
                        $names[$n] = $n;
                    }
                }
            }
        }
        ksort($names);

        return $names;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('complain_id')->label(__('টিকিট'))->prefix('#')->searchable()->sortable()
                    ->description(fn (BillingTicket $r) => $r->isDesk() ? 'Desk' : null),
                TextColumn::make('customer_name')->label(__('কাস্টমার'))
                    ->description(fn (BillingTicket $r) => trim('ID '.$r->customer_id.' · '.$r->mobile.(($c = $r->complainNumber()) && $c !== $r->mobile ? __(' · অভিযোগ: ').\Illuminate\Support\Str::limit($c, 20) : ''), ' ·'))
                    ->searchable(['customer_name', 'customer_id', 'mobile', 'username'])
                    ->wrap()->width('15rem'),   // a long complaint number in the line under the name made it very wide
                TextColumn::make('zone')->label('Zone')->description(fn (BillingTicket $r) => $r->subzone)->wrap()->toggleable(),
                TextColumn::make('category')->label(__('সমস্যা'))->wrap(),
                TextColumn::make('priority')->label('Priority')->badge()
                    ->formatStateUsing(fn (?string $state) => BillingTicket::PRIORITIES[$state] ?? $state)
                    ->color(fn (?string $state) => ['high' => 'danger', 'medium' => 'warning', 'low' => 'gray'][$state] ?? 'gray')
                    ->toggleable(),
                TextColumn::make('state')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => __(BillingTicket::STATES[$state] ?? $state))
                    ->color(fn (string $state) => ['pending' => 'danger', 'processing' => 'warning', 'solved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('person')->label(__('টেকনিশিয়ান'))
                    ->state(fn (BillingTicket $r) => $r->solved_by ?? $r->assigned_to)->placeholder(__('কেউ না'))->wrap(),
                TextColumn::make('opened_at')->label(__('খোলা হয়েছে'))->dateTime('d M, g:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (BillingTicket $r) => $r->created_by ? __('খুলেছেন :name', ['name' => $r->created_by]) : null)->wrap(),
                TextColumn::make('duration')->label(__('সময় লেগেছে'))
                    ->state(fn (BillingTicket $r) => $r->duration())
                    ->description(fn (BillingTicket $r) => $r->isOpen() ? __('এখনো খোলা') : null),
            ])
            ->filters([
                Filter::make('date')
                    ->schema([
                        Select::make('field')->label(__('কোন তারিখ'))->default('opened_at')->selectablePlaceholder(false)
                            ->options(['opened_at' => __('খোলার তারিখ'), 'solved_at' => __('সমাধানের তারিখ')]),
                        Select::make('period')->label(__('সময়'))->placeholder(__('সব সময়'))->live()
                            ->options(['today' => __('আজ'), 'yesterday' => __('গতকাল'), '7d' => __('শেষ ৭ দিন'), '30d' => __('শেষ ৩০ দিন'),
                                'this_month' => __('এই মাস'), 'last_month' => __('গত মাস'), 'custom' => __('নির্দিষ্ট তারিখ')]),
                        DatePicker::make('from')->label(__('থেকে'))->visible(fn ($get) => $get('period') === 'custom'),
                        DatePicker::make('until')->label(__('পর্যন্ত'))->visible(fn ($get) => $get('period') === 'custom'),
                    ])
                    ->columns(4)->columnSpan(2)
                    ->query(function (Builder $query, array $data) {
                        [$from, $until] = static::period($data);
                        $field = ($data['field'] ?? null) === 'solved_at' ? 'solved_at' : 'opened_at';

                        return $query->when($from, fn ($q) => $q->where($field, '>=', $from->utc()))
                            ->when($until, fn ($q) => $q->where($field, '<', $until->utc()));
                    })
                    ->indicateUsing(function (array $data) {
                        [$from, $until] = static::period($data);
                        if (! $from && ! $until) {
                            return null;
                        }
                        $label = ($data['field'] ?? null) === 'solved_at' ? __('সমাধান') : __('খোলা');

                        return $label.': '.($from?->format('d M') ?? '…').' – '.($until?->subDay()->format('d M') ?? __('আজ'));
                    }),
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::listOptions('zones', 'zone'))->searchable(),
                SelectFilter::make('category')->label(__('সমস্যা'))->options(fn () => static::listOptions('categories', 'category'))->searchable(),
                SelectFilter::make('employee')->label(__('কর্মী (assign / সমাধান)'))->options(fn () => static::employees())->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $name) => $q->forEmployee($name))),
                SelectFilter::make('team')->label(__('ফিল্ড টিম'))->options(fn () => \App\Filament\App\TicketActions::teamOptions())
                    ->query(function (Builder $query, array $data) {
                        $team = ($data['value'] ?? null) ? \App\Models\StaffTeam::where('company_id', Filament::getTenant()?->getKey() ?? 0)->find((int) $data['value']) : null;
                        if (! $team) {
                            return $query;
                        }
                        // any member assigned to / solved the ticket (members are billing employee ids; tickets hold names)
                        $names = \App\Filament\App\TicketActions::options('employees');
                        $people = collect($team->members)->map(fn ($m) => $names[$m] ?? null)->filter()->values();

                        return $people->isEmpty() ? $query->whereRaw('false')
                            : $query->where(fn ($q) => $people->each(fn ($n) => $q->orWhere(fn ($w) => $w->forEmployee($n))));
                    }),
                SelectFilter::make('priority')->label('Priority')->options(BillingTicket::PRIORITIES),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->recordActions([TicketActions::assign()->iconButton()->tooltip(__('কর্মী assign')), TicketActions::delete()->iconButton()->tooltip(__('ডিলিট')),
                ViewAction::make()->iconButton()->tooltip(__('বিস্তারিত'))->label(__('বিস্তারিত'))->modalWidth('4xl')->modalHeading(fn (BillingTicket $record) => __('টিকিট #:id', ['id' => $record->complain_id]))
                ->extraModalFooterActions(fn () => [TicketActions::step(-1), TicketActions::solve(), TicketActions::edit()->button(), TicketActions::assign()->label(__('কর্মী যোগ / বাদ'))->button(), TicketActions::delete()->button(), TicketActions::step(1)])])
            ->recordAction('view');
    }

    /** [from, until) in Bangladesh time for the date filter, or [null, null]. */
    public static function period(array $data): array
    {
        $today = Carbon::now('Asia/Dhaka')->startOfDay();

        return match ($data['period'] ?? null) {
            'today' => [$today, null],
            'yesterday' => [$today->copy()->subDay(), $today],
            '7d' => [$today->copy()->subDays(6), null],
            '30d' => [$today->copy()->subDays(29), null],
            'this_month' => [$today->copy()->startOfMonth(), null],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->startOfMonth()],
            'custom' => [
                ($data['from'] ?? null) ? Carbon::parse($data['from'], 'Asia/Dhaka')->startOfDay() : null,
                ($data['until'] ?? null) ? Carbon::parse($data['until'], 'Asia/Dhaka')->addDay()->startOfDay() : null,
            ],
            default => [null, null],
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Placeholder::make('details')->hiddenLabel()->columnSpanFull()
                ->content(fn (BillingTicket $record) => TicketActions::details($record)),
        ]);
    }

    /** Explicit company scope in addition to Filament tenancy. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListTickets::route('/')];
    }
}
