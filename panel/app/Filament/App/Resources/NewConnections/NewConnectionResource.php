<?php

namespace App\Filament\App\Resources\NewConnections;

use App\Filament\App\Resources\NewConnections\Pages\ManageNewConnections;
use App\Models\NewConnection;
use App\Models\Technician;
use App\Services\OnuEntry;
use App\Support\Onu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** New lines and fiber conversions sold: price, deposit, due, who took the order and who holds the money. */
class NewConnectionResource extends Resource
{
    protected static ?string $model = NewConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    public static function getNavigationLabel(): string
    {
        return __('নতুন সংযোগ');
    }

    public static function getModelLabel(): string
    {
        return __('নতুন সংযোগ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('নতুন সংযোগ');
    }

    protected static ?string $slug = 'new-connections';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return Onu::canAccess();
    }

    private static function company(): int
    {
        return (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    private static function money($v): string
    {
        return number_format((float) $v, 0).' টাকা';
    }

    public static function form(Schema $schema): Schema
    {
        $staff = fn () => Technician::pickerOptions(static::company());

        return $schema->columns(3)->components([
            DatePicker::make('connected_on')->label('সংযোগের তারিখ')->required()->default(fn () => now('Asia/Dhaka')->toDateString()),
            Select::make('connection_type')->label('ধরন')->required()->default(Onu::CONNECTION_TYPES[0])
                ->options(array_combine(Onu::CONNECTION_TYPES, Onu::CONNECTION_TYPES)),
            Radio::make('customer_kind')->label('কার জন্য')->inline()->required()->default('customer')->live()
                ->options(['customer' => 'কাস্টমার', Onu::OTHER => 'অন্যান্য']),
            Select::make('customer_code')->label('কাস্টমার')->searchable()->columnSpanFull()
                ->required(fn ($get) => $get('customer_kind') !== Onu::OTHER)
                ->visible(fn ($get) => $get('customer_kind') !== Onu::OTHER)
                ->helperText('কাস্টমার ID, নাম, মোবাইল বা PPPoE ID লিখে খুঁজুন')
                ->getSearchResultsUsing(fn (string $search) => OnuEntry::searchCustomers(static::company(), $search))
                ->getOptionLabelUsing(fn ($value) => OnuEntry::customerLabel(static::company(), $value))
                ->rule(fn (?NewConnection $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                    $code = Onu::resolveCustomer(static::company(), $value)[0];
                    if ($code && NewConnection::where('company_id', static::company())->where('customer_code', $code)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                        $fail('এই কাস্টমারের সংযোগ আগেই এন্ট্রি করা আছে');
                    }
                })
                ->live()
                ->afterStateUpdated(function ($state, callable $set, $get) {
                    $c = $state ? \App\Models\BillingCustomer::where('company_id', static::company())
                        ->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$state])->first() : null;
                    if ($c) {
                        foreach (['name' => $c->name, 'mobile' => $c->mobile, 'address' => $c->address] as $k => $v) {
                            if (blank($get($k))) {
                                $set($k, $v);
                            }
                        }
                    }
                }),
            TextInput::make('name')->label('নাম')->required()->columnSpan(2),
            TextInput::make('mobile')->label('মোবাইল')->required()->tel()->maxLength(20),
            TextInput::make('address')->label('ঠিকানা')->required()->columnSpanFull(),
            CheckboxList::make('materials')->label('মালামাল')->options(array_combine(Onu::MATERIALS, Onu::MATERIALS))->columns(3)
                ->columnSpanFull(),
            TextInput::make('total')->label('মোট টাকা')->numeric()->minValue(0)->required()->live(onBlur: true),
            TextInput::make('deposit')->label('জমা')->numeric()->minValue(0)->required()->live(onBlur: true)
                ->helperText(fn (?NewConnection $record) => $record?->payments()->exists() ? 'পরে আদায় করা টাকা এর মধ্যেই আছে' : null),
            TextInput::make('due_display')->label('বাকি')->disabled()->dehydrated(false)
                ->placeholder(fn ($get, ?NewConnection $record) => $record && (float) $record->total === (float) $get('total') && (float) $record->deposit === (float) $get('deposit')
                    ? number_format((float) $record->due, 2) : number_format((float) $get('total') - (float) $get('deposit'), 2)),
            Select::make('order_taker_id')->label('অর্ডার গ্রহীতা')->required()->options($staff)->searchable(),
            Select::make('money_with_id')->label('টাকা যার কাছে')->required()->options($staff)->searchable(),
            Section::make('ONU (ঐচ্ছিক)')->description('ব্র্যান্ড আর MAC দিলে এই কাস্টমারের ONU বরাদ্দও একসাথে হবে')
                ->visibleOn('create')->columnSpanFull()->columns(2)->schema([
                    Select::make('onu_brand_id')->label('ব্র্যান্ড (স্টক থেকে)')->options(fn () => Onu::brandOptions(static::company())),
                    TextInput::make('onu_mac')->label('ONU MAC')->maxLength(64)->requiredWith('onu_brand_id'),
                    Select::make('onu_technicians')->label('বরাদ্দ গ্রহীতা')->multiple()->options($staff)->columnSpanFull()
                        ->helperText('খালি রাখলে অর্ডার গ্রহীতা'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('connected_on', 'desc')
            ->paginated([25, 50, 100, 250])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('customer_code')->label('ID')->state(fn (NewConnection $r) => $r->customer_kind === Onu::OTHER
                    ? 'অন্যান্য' : $r->customer_code)
                    ->description(fn (NewConnection $r) => $r->customer_kind === Onu::OTHER ? $r->legacy_customer_code : null)
                    ->searchable(query: fn (Builder $q, string $search) => $q->where(fn ($w) => $w
                        ->whereRaw("ltrim(new_connections.customer_code, '0') = ltrim(?, '0')", [trim($search)])
                        ->orWhere('new_connections.mobile', 'like', '%'.trim($search).'%')
                        ->orWhere('new_connections.name', 'ilike', '%'.trim($search).'%'))),
                TextColumn::make('connected_on')->label('তারিখ')->date('d M Y')->sortable(),
                TextColumn::make('name')->label('নাম ও ঠিকানা')->description(fn (NewConnection $r) => $r->address)->wrap(),
                TextColumn::make('mobile')->label('মোবাইল'),
                TextColumn::make('connection_type')->label('ধরন')->badge()->color('gray'),
                TextColumn::make('materials')->label('মালামাল')->badge()->separator(',')
                    ->description(fn (NewConnection $r) => $r->latestOnu ? $r->latestOnu->brand?->name.' · '.$r->latestOnu->macLabel() : null),
                TextColumn::make('total')->label('মূল্য')->numeric(0)->sortable(),
                TextColumn::make('deposit')->label('জমা')->numeric(0),
                TextColumn::make('due')->label('বাকি')->numeric(0)->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : null)->weight(fn ($state) => (float) $state > 0 ? 'bold' : null),
                TextColumn::make('orderTaker.name')->label('অর্ডার গ্রহীতা')->placeholder('—'),
                TextColumn::make('moneyWith.name')->label('টাকা যার কাছে')->placeholder('—'),
                TextColumn::make('user.name')->label('এন্ট্রি করেছেন')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date')->schema([DatePicker::make('from')->label('শুরুর তারিখ'), DatePicker::make('until')->label('শেষ তারিখ')])
                    ->columns(2)->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('connected_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('connected_on', '<=', $d))),
                SelectFilter::make('connection_type')->label('ধরন')->options(array_combine(Onu::CONNECTION_TYPES, Onu::CONNECTION_TYPES)),
                SelectFilter::make('material')->label('মালামাল')->options(array_combine(Onu::MATERIALS, Onu::MATERIALS))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($q, $v) => $q->whereJsonContains('materials', $v))),
                SelectFilter::make('order_taker_id')->label('অর্ডার গ্রহীতা')->searchable()->options(fn () => Technician::pickerOptions(static::company())),
                SelectFilter::make('money_with_id')->label('টাকা যার কাছে')->searchable()->options(fn () => Technician::pickerOptions(static::company())),
                TernaryFilter::make('has_due')->label('বাকি')->trueLabel('বাকি আছে')->falseLabel('বাকি নেই')
                    ->queries(true: fn (Builder $q) => $q->where('due', '>', 0), false: fn (Builder $q) => $q->where('due', '<=', 0)),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->headerActions([
                CreateAction::make()->label('নতুন সংযোগ যোগ করুন')->slideOver()->modalHeading('নতুন সংযোগ')
                    ->modalSubmitActionLabel('সেভ করুন')
                    ->using(fn (array $data) => OnuEntry::saveConnectionForm(null, $data, static::company(), auth()->id())),
            ])
            ->recordActions([
                Action::make('collect')->label('বাকি আদায়')->icon(Heroicon::OutlinedBanknotes)->color('danger')
                    ->visible(fn (NewConnection $r) => (float) $r->due > 0)
                    ->modalHeading(fn (NewConnection $r) => 'বাকি আদায়: '.$r->name)
                    ->modalDescription(fn (NewConnection $r) => 'মোট বাকি '.static::money($r->due).'। আগের টাকা যার কাছে: '.($r->moneyWith?->name ?? '—'))
                    ->fillForm(fn (NewConnection $r) => ['paid_on' => now('Asia/Dhaka')->toDateString(), 'collected_by_id' => $r->money_with_id, 'discount' => 0])
                    ->schema([
                        DatePicker::make('paid_on')->label('তারিখ')->required(),
                        TextInput::make('paid')->label('জমার পরিমাণ')->numeric()->minValue(0)->required(),
                        TextInput::make('discount')->label('ছাড়')->numeric()->minValue(0),
                        Select::make('collected_by_id')->label('কে নিয়েছেন')->required()->options(fn () => Technician::pickerOptions(static::company())),
                    ])
                    ->action(function (NewConnection $record, array $data) {
                        $record->collect((float) $data['paid'], (float) ($data['discount'] ?? 0), $data['collected_by_id'], auth()->id(), $data['paid_on']);
                        Notification::make()->success()->title('আদায় সেভ হয়েছে')->body('এখন বাকি '.static::money($record->fresh()->due))->send();
                    }),
                Action::make('payments')->label('আদায়ের ইতিহাস')->icon(Heroicon::OutlinedClock)->color('gray')
                    ->visible(fn (NewConnection $r) => $r->payments()->exists())
                    ->modalSubmitAction(false)->modalCancelActionLabel('বন্ধ')
                    ->modalContent(fn (NewConnection $r) => new HtmlString('<table class="w-full text-sm"><tr class="text-left"><th>তারিখ</th><th>জমা</th><th>ছাড়</th><th>কে নিয়েছেন</th></tr>'
                        .$r->payments()->with('collectedBy')->get()->map(fn ($p) => '<tr><td>'.$p->paid_on->format('d M Y').'</td><td>'.e(number_format((float) $p->paid, 0))
                            .'</td><td>'.e(number_format((float) $p->discount, 0)).'</td><td>'.e($p->collectedBy?->name ?? '—').'</td></tr>')->join('').'</table>')),
                EditAction::make()->slideOver()->modalHeading('সংযোগ এডিট')->modalSubmitActionLabel('সেভ করুন')
                    ->using(fn (NewConnection $record, array $data) => OnuEntry::saveConnectionForm($record, $data, static::company(), auth()->id())),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('new_connections.company_id', static::company())
            ->with(['orderTaker', 'moneyWith', 'user', 'latestOnu.brand']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNewConnections::route('/')];
    }
}
