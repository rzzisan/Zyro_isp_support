<?php

namespace App\Filament\App\Resources\OnuAssignments;

use App\Filament\App\Resources\OnuAssignments\Pages\ManageOnuAssignments;
use App\Models\OnuAssignment;
use App\Models\OnuBrand;
use App\Models\Technician;
use App\Models\User;
use App\Services\OnuEntry;
use App\Support\Onu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** ONUs handed out: customer (or "other"), brand, MAC, purpose, who fitted it; New Connection also records the sale. */
class OnuAssignmentResource extends Resource
{
    protected static ?string $model = OnuAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function getNavigationLabel(): string
    {
        return __('ONU বরাদ্দ');
    }

    public static function getModelLabel(): string
    {
        return __('ONU বরাদ্দ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ONU বরাদ্দ');
    }

    protected static ?string $slug = 'onu-assignments';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Onu::canAccess();
    }

    private static function company(): int
    {
        return (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    public static function stickerUrl(array $ids): string
    {
        return route('onu.stickers', ['company' => Filament::getTenant()->slug, 'ids' => implode(',', $ids)]);
    }

    /** Under the MAC field: the formatted MAC, a wrong length, the same MAC given before, and the OLT's live view. */
    private static function macHint(?string $mac, ?OnuAssignment $record): ?string
    {
        $norm = Onu::normalizeMac($mac);
        if (! $norm) {
            return null;
        }
        $parts = [Onu::validMac($norm) ? Onu::displayMac($norm) : __('⚠ MAC-এ ১২টা অক্ষর (0-9, A-F) থাকার কথা, এখানে :nটা', ['n' => strlen($norm)])];
        $before = OnuAssignment::where('company_id', static::company())->where('mac_norm', $norm)
            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->latest('assigned_at')->first();
        if ($before) {
            $parts[] = __('⚠ এই MAC আগে দেওয়া হয়েছে: ').($before->customer_code ? 'ID '.$before->customer_code : __('অন্যান্য')).', '
                .$before->assigned_at->timezone('Asia/Dhaka')->format('d M Y').' ('.$before->purpose.')';
        }
        if ($onu = Onu::liveOnus(static::company())[$norm] ?? null) {
            $parts[] = __('OLT-এ আছে: ').$onu->name.' · '.($onu->online ? __('অনলাইন') : __('অফলাইন')).($onu->rx_dbm !== null ? ' · '.$onu->rx_dbm.' dBm' : '');
        }

        return implode(' | ', $parts);
    }

    public static function form(Schema $schema): Schema
    {
        $staff = fn () => Technician::pickerOptions(static::company());

        return $schema->columns(2)->components([
            DateTimePicker::make('assigned_at')->label(__('তারিখ ও সময়'))->required()->seconds(false)->timezone('Asia/Dhaka')
                ->default(fn () => now()),
            Radio::make('customer_kind')->label(__('কার জন্য'))->inline()->required()->default('customer')->live()
                ->options(['customer' => __('কাস্টমার'), Onu::OTHER => __('অন্যান্য (কাস্টমার ID নেই)')]),
            Select::make('customer_code')->label(__('কাস্টমার'))->searchable()->columnSpanFull()
                ->required(fn ($get) => $get('customer_kind') !== Onu::OTHER)
                ->visible(fn ($get) => $get('customer_kind') !== Onu::OTHER)
                ->helperText(__('কাস্টমার ID, নাম, মোবাইল বা PPPoE ID লিখে খুঁজুন'))
                ->getSearchResultsUsing(fn (string $search) => OnuEntry::searchCustomers(static::company(), $search))
                ->getOptionLabelUsing(fn ($value) => OnuEntry::customerLabel(static::company(), $value))
                ->live()
                ->afterStateUpdated(function ($state, callable $set, $get) {
                    $c = $state ? \App\Models\BillingCustomer::where('company_id', static::company())
                        ->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$state])->first() : null;
                    if ($c) {
                        foreach (['nc_name' => $c->name, 'nc_mobile' => $c->mobile, 'nc_address' => $c->address] as $k => $v) {
                            if (blank($get($k))) {
                                $set($k, $v);
                            }
                        }
                    }
                }),
            TextInput::make('note')->label(__('বিবরণ (ঐচ্ছিক)'))->columnSpanFull()->maxLength(255)
                ->visible(fn ($get) => $get('customer_kind') === Onu::OTHER)
                ->placeholder(__('যেমন: অফিসের পয়েন্টে / কার কাছে বিক্রি')),
            Select::make('brand_id')->label(__('ব্র্যান্ড (স্টক থেকে)'))->required()
                ->options(fn (?OnuAssignment $record) => Onu::brandOptions(static::company(), $record?->brand_id))
                ->getOptionLabelUsing(fn ($value) => OnuBrand::find($value)?->name),
            TextInput::make('mac_raw')->label('ONU MAC')->required()->maxLength(64)->live(onBlur: true)
                ->helperText(fn ($state, ?OnuAssignment $record) => static::macHint($state, $record))
                ->rule(fn ($get, ?OnuAssignment $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                    $code = $get('customer_kind') === Onu::OTHER ? null : Onu::resolveCustomer(static::company(), $get('customer_code'))[0];
                    if ($code && OnuAssignment::where('company_id', static::company())->where('customer_code', $code)
                        ->where('mac_norm', Onu::normalizeMac($value))->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                        $fail(__('এই কাস্টমারের এই MAC আগেই এন্ট্রি করা আছে'));
                    }
                }),
            Select::make('purpose')->label(__('কারণ'))->required()->live()
                ->options(array_combine(Onu::PURPOSES, array_map('__', Onu::PURPOSES))),
            CheckboxList::make('technician_ids')->label(__('বরাদ্দ গ্রহীতা (কে লাগিয়েছেন)'))->columnSpanFull()->columns(4)->searchable()
                ->options($staff),

            Section::make(__('নতুন সংযোগ'))->description(__('কারণ "New Connection" হলে এই কাস্টমারের সংযোগের হিসাবও একসাথে সেভ হয়'))
                ->columnSpanFull()->visible(fn ($get) => $get('purpose') === 'New Connection')
                ->schema([Grid::make(3)->schema([
                    DatePicker::make('nc_connected_on')->label(__('সংযোগের তারিখ'))
                        ->default(fn () => now('Asia/Dhaka')->toDateString()),
                    Select::make('nc_type')->label(__('ধরন'))->required()->default(Onu::CONNECTION_TYPES[0])
                        ->options(array_combine(Onu::CONNECTION_TYPES, array_map('__', Onu::CONNECTION_TYPES))),
                    TextInput::make('nc_mobile')->label(__('মোবাইল'))->required()->tel()->maxLength(20),
                    TextInput::make('nc_name')->label(__('নাম'))->required()->columnSpanFull(),
                    TextInput::make('nc_address')->label(__('ঠিকানা'))->required()->columnSpanFull(),
                    CheckboxList::make('nc_materials')->label(__('মালামাল'))->options(array_combine(Onu::MATERIALS, array_map('__', Onu::MATERIALS)))
                        ->columns(3)->default([__('অনু')])->columnSpanFull(),
                    TextInput::make('nc_total')->label(__('মোট টাকা'))->numeric()->minValue(0)->required()->live(onBlur: true),
                    TextInput::make('nc_deposit')->label(__('জমা'))->numeric()->minValue(0)->required()->live(onBlur: true),
                    TextInput::make('nc_due')->label(__('বাকি'))->disabled()->dehydrated(false)
                        ->formatStateUsing(fn ($get) => number_format((float) $get('nc_total') - (float) $get('nc_deposit'), 2))
                        ->placeholder(fn ($get) => number_format((float) $get('nc_total') - (float) $get('nc_deposit'), 2)),
                    Select::make('nc_order_taker_id')->label(__('অর্ডার গ্রহীতা'))->required()->options($staff)->searchable(),
                    Select::make('nc_money_with_id')->label(__('টাকা যার কাছে'))->required()->options($staff)->searchable(),
                ])]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('assigned_at', 'desc')
            ->paginated([25, 50, 100, 250])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('assigned_at')->label(__('তারিখ'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('customer_code')->label(__('কাস্টমার'))
                    ->state(fn (OnuAssignment $r) => $r->isOther() ? __('অন্যান্য') : 'ID '.$r->customer_code)
                    ->description(fn (OnuAssignment $r) => $r->isOther() ? ($r->note ?: $r->legacy_customer_code)
                        : collect([$r->customer?->name ?? $r->connection?->name, $r->customer?->mobile ?? $r->connection?->mobile])->filter()->join(' · '))
                    ->searchable(query: fn (Builder $q, string $search) => $q->where(fn ($w) => $w
                        ->whereRaw("ltrim(onu_assignments.customer_code, '0') = ltrim(?, '0')", [trim($search)])
                        ->orWhere('onu_assignments.mac_norm', 'like', '%'.Onu::normalizeMac($search).'%')
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%")))),
                TextColumn::make('address')->label(__('ঠিকানা'))->toggleable(isToggledHiddenByDefault: true)->wrap()
                    ->state(fn (OnuAssignment $r) => $r->customer?->address ?? $r->connection?->address),
                TextColumn::make('brand.name')->label(__('ব্র্যান্ড')),
                TextColumn::make('mac_norm')->label('MAC')->state(fn (OnuAssignment $r) => $r->macLabel())
                    ->color(fn (OnuAssignment $r) => Onu::validMac($r->mac_norm) ? null : 'warning')
                    ->description(function (OnuAssignment $r) {
                        $o = $r->liveOnu();

                        return $o ? ($o->online ? __('অনলাইন') : __('অফলাইন')).($o->rx_dbm !== null ? ' · '.$o->rx_dbm.' dBm' : '') : null;
                    }),
                TextColumn::make('purpose')->label(__('কারণ'))->badge()
                    ->color(fn (string $state) => match ($state) { 'New Connection' => 'success', 'Warranty' => 'warning', 'Convert to ONU' => 'info', default => 'gray' }),
                TextColumn::make('technicians.name')->label(__('বরাদ্দ গ্রহীতা'))->listWithLineBreaks()->placeholder('—'),
                TextColumn::make('user.name')->label(__('এন্ট্রি করেছেন'))->placeholder('—'),
            ])
            ->filters([
                Filter::make('date')->schema([DatePicker::make('from')->label(__('শুরুর তারিখ')), DatePicker::make('until')->label(__('শেষ তারিখ'))])
                    ->columns(2)->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('assigned_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('assigned_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc()))),
                SelectFilter::make('technician')->label(__('বরাদ্দ গ্রহীতা'))->searchable()
                    ->options(fn () => Technician::pickerOptions(static::company()))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($q, $v) => $q->whereHas('technicians', fn ($t) => $t->whereKey($v)))),
                SelectFilter::make('brand_id')->label(__('ব্র্যান্ড'))
                    ->options(fn () => OnuBrand::where('company_id', static::company())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('user_id')->label(__('এন্ট্রি করেছেন'))
                    ->options(fn () => User::whereIn('id', OnuAssignment::where('company_id', static::company())->whereNotNull('user_id')
                        ->distinct()->select('user_id'))->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('purpose')->label(__('কারণ'))->options(array_combine(Onu::PURPOSES, array_map('__', Onu::PURPOSES))),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->headerActions([
                CreateAction::make()->label(__('ONU বরাদ্দ করুন'))->slideOver()->modalHeading(__('ONU বরাদ্দ করুন'))
                    ->modalSubmitActionLabel(__('সেভ করুন'))
                    ->using(fn (array $data) => OnuEntry::save(null, $data, static::company(), auth()->id()))
                    ->successNotification(fn (OnuAssignment $record) => Notification::make()->success()->title(__('সেভ হয়েছে'))
                        ->actions([Action::make('sticker')->label(__('স্টিকার প্রিন্ট'))->button()
                            ->url(static::stickerUrl([$record->id]), shouldOpenInNewTab: true)])),
            ])
            ->recordActions([
                Action::make('sticker')->label(__('স্টিকার'))->icon(Heroicon::OutlinedPrinter)->color('gray')
                    ->url(fn (OnuAssignment $r) => static::stickerUrl([$r->id]), shouldOpenInNewTab: true),
                EditAction::make()->slideOver()->modalHeading(__('ONU বরাদ্দ এডিট'))->modalSubmitActionLabel(__('সেভ করুন'))
                    ->mutateRecordDataUsing(fn (array $data, OnuAssignment $record) => [...$data, ...OnuEntry::connectionState($record),
                        'technician_ids' => $record->technicians->modelKeys()])
                    ->using(fn (OnuAssignment $record, array $data) => OnuEntry::save($record, $data, static::company(), auth()->id())),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('stickers')->label(__('বাছাইগুলোর স্টিকার'))->icon(Heroicon::OutlinedPrinter)
                    ->action(fn (Collection $records, $livewire) => $livewire->js('window.open('.json_encode(static::stickerUrl($records->modelKeys())).', "_blank")'))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('onu_assignments.company_id', static::company())
            ->with(['brand', 'customer', 'connection', 'technicians', 'user']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOnuAssignments::route('/')];
    }
}
