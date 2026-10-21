<?php

namespace App\Filament\App\Resources\WaCampaignMessages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\WaCampaignMessages\Pages\ListWaCampaignMessages;
use App\Models\BillingCustomer;
use App\Models\WaCampaign;
use App\Models\WaCampaignMessage;
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

/** Every WhatsApp template message sent from the Desk, one row per number, with Meta's status. Read-only. */
class WaCampaignMessageResource extends Resource
{
    protected static ?string $model = WaCampaignMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function getNavigationLabel(): string
    {
        return __('WhatsApp লগ');
    }

    protected static ?string $modelLabel = 'WhatsApp';

    public static function getPluralModelLabel(): string
    {
        return __('WhatsApp লগ');
    }

    protected static ?string $slug = 'whatsapp-log';

    protected static string|\UnitEnum|null $navigationGroup = 'SMS ও WhatsApp';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return Menu::can('wa_campaign');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('কোনো WhatsApp মেসেজ নেই'))
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (WaCampaignMessage $r) => $r->sent_at ? __('গেছে :time', ['time' => $r->sent_at->timezone('Asia/Dhaka')->format('g:i:s A')]) : null),
                TextColumn::make('name')->label(__('কাকে'))->placeholder('—')
                    ->description(fn (WaCampaignMessage $r) => trim(($r->customer_id ? 'ID '.$r->customer_id.' · ' : '').$r->wa_number))
                    ->url(fn (WaCampaignMessage $r) => static::customerUrl($r))
                    ->searchable(['name', 'wa_number', 'customer_id']),
                TextColumn::make('text')->label(__('লেখা'))->wrap()->limit(160)->searchable(),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => __(WaCampaignMessage::STATUSES[$state] ?? $state))
                    ->color(fn (string $state) => WaCampaignMessage::COLORS[$state] ?? 'gray')
                    ->description(fn (WaCampaignMessage $r) => $r->error ? trim(($r->error_code ? "#{$r->error_code} " : '').$r->error) : null)->wrap(),
                TextColumn::make('campaign.template_name')->label(__('টেমপ্লেট'))->toggleable(),
                TextColumn::make('user.name')->label(__('কে পাঠালেন'))->placeholder('—')->toggleable(),
                TextColumn::make('delivered_at')->label(__('পৌঁছানোর সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('read_at')->label(__('পড়ার সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))->options(array_map('__', WaCampaignMessage::STATUSES))->multiple(),
                SelectFilter::make('user_id')->label(__('কে পাঠালেন'))->relationship('user', 'name'),
                SelectFilter::make('campaign_id')->label(__('পাঠানো (ক্রম)'))
                    ->options(fn () => WaCampaign::where('company_id', Filament::getTenant()?->getKey() ?? 0)
                        ->latest('id')->limit(50)->get()
                        ->mapWithKeys(fn ($c) => [$c->id => '#'.$c->id.' · '.$c->template_name.' · '.$c->created_at->timezone('Asia/Dhaka')->format('d M g:i A').' · '.__(':n জন', ['n' => number_format($c->total)])])),
                Filter::make('date')->schema([DatePicker::make('from')->label(__('থেকে')), DatePicker::make('until')->label(__('পর্যন্ত'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc()))),
            ]);
    }

    public static function customerUrl(WaCampaignMessage $r): ?string
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
        return ['index' => ListWaCampaignMessages::route('/')];
    }
}
