<?php

namespace App\Filament\App\Resources\SmsMessages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\SmsMessages\Pages\ListSmsMessages;
use App\Models\BillingCustomer;
use App\Models\SmsCampaign;
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
    protected static ?string $model = SmsMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'SMS লগ';

    protected static ?string $modelLabel = 'SMS';

    protected static ?string $pluralModelLabel = 'SMS লগ';

    protected static ?string $slug = 'sms-log';

    protected static string|\UnitEnum|null $navigationGroup = 'SMS';

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
            ->emptyStateHeading('কোনো SMS নেই')
            ->columns([
                TextColumn::make('created_at')->label('সময়')->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (SmsMessage $r) => $r->sent_at ? 'গেছে '.$r->sent_at->timezone('Asia/Dhaka')->format('g:i:s A') : null),
                TextColumn::make('name')->label('কাকে')->placeholder('—')
                    ->description(fn (SmsMessage $r) => trim(($r->customer_id ? 'ID '.$r->customer_id.' · ' : '').$r->mobile))
                    ->url(fn (SmsMessage $r) => static::customerUrl($r))
                    ->searchable(['name', 'mobile', 'customer_id']),
                TextColumn::make('text')->label('লেখা')->wrap()->limit(160)->searchable(),
                TextColumn::make('parts')->label('ভাগ')->numeric()->toggleable(),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->formatStateUsing(fn (string $state) => SmsMessage::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => SmsMessage::COLORS[$state] ?? 'gray')
                    ->description(fn (SmsMessage $r) => $r->error ?: ($r->gateway_text && $r->status !== 'delivered' ? $r->gateway_text : null)),
                TextColumn::make('user.name')->label('কে পাঠালেন')->placeholder('—')->toggleable(),
                TextColumn::make('message_id')->label('গেটওয়ে ID')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivered_at')->label('পৌঁছানোর সময়')->dateTime('d M Y, g:i A', 'Asia/Dhaka')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('অবস্থা')->options(SmsMessage::STATUSES)->multiple(),
                SelectFilter::make('user_id')->label('কে পাঠালেন')->relationship('user', 'name'),
                SelectFilter::make('campaign_id')->label('পাঠানো (ক্রম)')
                    ->options(fn () => SmsCampaign::where('company_id', Filament::getTenant()?->getKey() ?? 0)
                        ->latest('id')->limit(50)->get()
                        ->mapWithKeys(fn ($c) => [$c->id => '#'.$c->id.' · '.$c->created_at->timezone('Asia/Dhaka')->format('d M g:i A').' · '.number_format($c->total).' জন'])),
                Filter::make('date')->schema([DatePicker::make('from')->label('থেকে'), DatePicker::make('until')->label('পর্যন্ত')])
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
