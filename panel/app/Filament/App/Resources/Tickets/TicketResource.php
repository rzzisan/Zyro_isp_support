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

/** Billing software support tickets, synced every 5 minutes. Read-only. */
class TicketResource extends Resource
{
    protected static ?string $model = BillingTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'টিকিট';

    protected static ?string $modelLabel = 'টিকিট';

    protected static ?string $pluralModelLabel = 'টিকিট';

    protected static ?string $slug = 'tickets';

    protected static ?int $navigationSort = -5;

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
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('complain_id')->label('টিকিট')->prefix('#')->searchable()->sortable(),
                TextColumn::make('customer_name')->label('কাস্টমার')
                    ->description(fn (BillingTicket $r) => trim('ID '.$r->customer_id.' · '.$r->mobile.(($c = $r->complainNumber()) && $c !== $r->mobile ? ' · অভিযোগ: '.$c : ''), ' ·'))
                    ->searchable(['customer_name', 'customer_id', 'mobile', 'username']),
                TextColumn::make('zone')->label('Zone')->description(fn (BillingTicket $r) => $r->subzone)->toggleable(),
                TextColumn::make('category')->label('সমস্যা')->wrap(),
                TextColumn::make('priority')->label('Priority')->badge()
                    ->formatStateUsing(fn (?string $state) => BillingTicket::PRIORITIES[$state] ?? $state)
                    ->color(fn (?string $state) => ['high' => 'danger', 'medium' => 'warning', 'low' => 'gray'][$state] ?? 'gray')
                    ->toggleable(),
                TextColumn::make('state')->label('অবস্থা')->badge()
                    ->formatStateUsing(fn (string $state) => BillingTicket::STATES[$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'danger', 'processing' => 'warning', 'solved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('person')->label('টেকনিশিয়ান')
                    ->state(fn (BillingTicket $r) => $r->solved_by ?? $r->assigned_to)->placeholder('কেউ না')->wrap(),
                TextColumn::make('opened_at')->label('খোলা হয়েছে')->dateTime('d M, g:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (BillingTicket $r) => $r->created_by ? 'খুলেছেন '.$r->created_by : null),
                TextColumn::make('duration')->label('সময় লেগেছে')
                    ->state(fn (BillingTicket $r) => $r->duration())
                    ->description(fn (BillingTicket $r) => $r->isOpen() ? 'এখনো খোলা' : null),
            ])
            ->filters([
                Filter::make('date')
                    ->schema([
                        Select::make('field')->label('কোন তারিখ')->default('opened_at')->selectablePlaceholder(false)
                            ->options(['opened_at' => 'খোলার তারিখ', 'solved_at' => 'সমাধানের তারিখ']),
                        Select::make('period')->label('সময়')->placeholder('সব সময়')->live()
                            ->options(['today' => 'আজ', 'yesterday' => 'গতকাল', '7d' => 'শেষ ৭ দিন', '30d' => 'শেষ ৩০ দিন',
                                'this_month' => 'এই মাস', 'last_month' => 'গত মাস', 'custom' => 'নির্দিষ্ট তারিখ']),
                        DatePicker::make('from')->label('থেকে')->visible(fn ($get) => $get('period') === 'custom'),
                        DatePicker::make('until')->label('পর্যন্ত')->visible(fn ($get) => $get('period') === 'custom'),
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
                        $label = ($data['field'] ?? null) === 'solved_at' ? 'সমাধান' : 'খোলা';

                        return $label.': '.($from?->format('d M') ?? '…').' – '.($until?->subDay()->format('d M') ?? 'আজ');
                    }),
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::options('zone'))->searchable(),
                SelectFilter::make('category')->label('সমস্যা')->options(fn () => static::options('category'))->searchable(),
                SelectFilter::make('employee')->label('কর্মী (assign / সমাধান)')->options(fn () => static::employees())->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $name) => $q->forEmployee($name))),
                SelectFilter::make('priority')->label('Priority')->options(BillingTicket::PRIORITIES),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->recordActions([TicketActions::assign(), ViewAction::make()->label('বিস্তারিত')->modalWidth('4xl')->modalHeading(fn (BillingTicket $record) => 'টিকিট #'.$record->complain_id)
                ->extraModalFooterActions(fn () => [TicketActions::assign()->label('কর্মী যোগ / বাদ')->button()])])
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
        return $schema->components([
            Placeholder::make('details')->hiddenLabel()
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
