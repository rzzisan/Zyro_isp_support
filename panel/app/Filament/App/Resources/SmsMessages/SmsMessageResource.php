<?php

namespace App\Filament\App\Resources\SmsMessages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\SmsMessages\Pages\ListSmsMessages;
use App\Models\BillingCustomer;
use App\Models\SmsCampaign;
use App\Models\SmsGateway;
use App\Models\SmsMessage;
use App\Support\Menu;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Every SMS sent from the Desk, one row per number, with the gateway's answer and delivery status. Read-only. */
class SmsMessageResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = SmsMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    public static function getNavigationLabel(): string
    {
        return __('SMS লগ');
    }

    protected static ?string $modelLabel = 'SMS';

    public static function getPluralModelLabel(): string
    {
        return __('SMS লগ');
    }

    protected static ?string $slug = 'sms-log';

    protected static string|\UnitEnum|null $navigationGroup = 'SMS ও WhatsApp';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return Menu::can('sms');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('কোনো SMS নেই'))
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (SmsMessage $r) => $r->sent_at ? __('গেছে :time', ['time' => $r->sent_at->timezone('Asia/Dhaka')->format('g:i:s A')]) : null),
                TextColumn::make('name')->label(__('কাকে'))->placeholder('—')
                    ->description(fn (SmsMessage $r) => trim(($r->customer_id ? 'ID '.$r->customer_id.' · ' : '').$r->mobile))
                    ->url(fn (SmsMessage $r) => static::customerUrl($r))
                    ->searchable(['name', 'mobile', 'customer_id']),
                TextColumn::make('text')->label(__('লেখা'))->wrap()->limit(160)->searchable(),
                TextColumn::make('parts')->label(__('ভাগ'))->numeric()->toggleable(),
                TextColumn::make('provider')->label(__('গেটওয়ে'))->placeholder('—')->toggleable()
                    ->formatStateUsing(fn ($state) => SmsGateway::PROVIDERS[$state][0] ?? $state),
                TextColumn::make('cost')->label(__('খরচ'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2)),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => __(SmsMessage::STATUSES[$state] ?? $state))
                    ->color(fn (string $state) => SmsMessage::COLORS[$state] ?? 'gray')
                    ->description(fn (SmsMessage $r) => $r->error ?: ($r->gateway_text && $r->status !== 'delivered' ? $r->gateway_text : null)),
                TextColumn::make('user.name')->label(__('কে পাঠালেন'))->placeholder('—')->toggleable(),
                TextColumn::make('message_id')->label(__('গেটওয়ে ID'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivered_at')->label(__('পৌঁছানোর সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))->options(array_map('__', SmsMessage::STATUSES))->multiple(),
                SelectFilter::make('user_id')->label(__('কে পাঠালেন'))->relationship('user', 'name'),
                SelectFilter::make('provider')->label(__('গেটওয়ে'))->options(SmsGateway::providerOptions()),
                SelectFilter::make('campaign_id')->label(__('পাঠানো (ক্রম)'))
                    ->options(fn () => SmsCampaign::where('company_id', Filament::getTenant()?->getKey() ?? 0)
                        ->latest('id')->limit(50)->get()
                        ->mapWithKeys(fn ($c) => [$c->id => '#'.$c->id.' · '.$c->created_at->timezone('Asia/Dhaka')->format('d M g:i A').' · '.__(':n জন', ['n' => number_format($c->total)])])),
                Filter::make('date')->schema([DatePicker::make('from')->label(__('থেকে')), DatePicker::make('until')->label(__('পর্যন্ত'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc()))),
            ]);
    }

    public static function customerUrl(SmsMessage $r): ?string
    {
        if (! $r->customer_header_id) {
            return null;
        }
        $id = BillingCustomer::where('company_id', $r->company_id)->where('header_id', $r->customer_header_id)->value('id');

        return $id && Menu::can('customers') ? CustomerResource::getUrl('view', ['record' => $id]) : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListSmsMessages::route('/')];
    }
}
