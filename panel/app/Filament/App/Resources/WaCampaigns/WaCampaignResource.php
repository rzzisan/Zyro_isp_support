<?php

namespace App\Filament\App\Resources\WaCampaigns;

use App\Filament\App\Resources\WaCampaignMessages\WaCampaignMessageResource;
use App\Filament\App\Resources\WaCampaigns\Pages\ListWaCampaigns;
use App\Models\WaCampaign;
use App\Services\Engine;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every WhatsApp template send from the Desk with its progress; "WhatsApp পাঠান" opens the form. */
class WaCampaignResource extends Resource
{
    protected static ?string $model = WaCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public static function getNavigationLabel(): string
    {
        return __('WhatsApp পাঠান');
    }

    protected static ?string $modelLabel = 'WhatsApp';

    public static function getPluralModelLabel(): string
    {
        return __('WhatsApp পাঠান');
    }

    protected static ?string $slug = 'whatsapp-send';

    protected static string|\UnitEnum|null $navigationGroup = 'SMS ও WhatsApp';

    protected static ?int $navigationSort = 4;

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
            ->defaultSort('created_at', 'desc')
            ->poll(fn () => static::getEloquentQuery()->whereIn('status', ['queued', 'sending'])->exists() ? '5s' : null)
            ->emptyStateHeading(__('এখনো কোনো WhatsApp টেমপ্লেট পাঠানো হয়নি'))
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('user.name')->label(__('কে'))->placeholder('—'),
                TextColumn::make('template_name')->label(__('টেমপ্লেট'))->searchable()
                    ->description(fn (WaCampaign $r) => trim("{$r->template_language} · {$r->template_category}")),
                TextColumn::make('kind')->label(__('ধরন'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => $state === 'bulk' ? __('ফিল্টার') : __('নির্দিষ্ট')),
                TextColumn::make('total')->label(__('জন'))->numeric(),
                TextColumn::make('sent')->label(__('গেছে'))
                    ->formatStateUsing(fn ($state, WaCampaign $record) => number_format((int) $state).' / '.number_format($record->total))
                    ->description(fn (WaCampaign $record) => $record->failed ? __(':nটি ব্যর্থ', ['n' => number_format($record->failed)]) : null),
                TextColumn::make('delivered')->label(__('পৌঁছেছে'))->numeric()
                    ->description(fn (WaCampaign $record) => $record->read ? __(':n জন পড়েছেন', ['n' => number_format($record->read)]) : null),
                TextColumn::make('cost')->label(__('খরচ (আনুমানিক)'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).__(' টাকা'))->toggleable(),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => __(WaCampaign::STATUSES[$state] ?? $state))
                    ->color(fn (string $state) => ['queued' => 'gray', 'sending' => 'info', 'paused' => 'danger', 'done' => 'success', 'cancelled' => 'warning'][$state] ?? 'gray')
                    ->description(fn (WaCampaign $record) => $record->status === 'paused' ? $record->error : null)->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))->options(array_map('__', WaCampaign::STATUSES)),
                SelectFilter::make('user_id')->label(__('কে'))->relationship('user', 'name'),
            ])
            ->recordActions([
                Action::make('messages')->label(__('মেসেজগুলো'))->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->url(fn (WaCampaign $record) => WaCampaignMessageResource::getUrl('index', ['filters' => ['campaign_id' => ['value' => $record->id]]])),
                Action::make('resume')->label(__('আবার চালু'))->icon(Heroicon::OutlinedPlay)->color('success')
                    ->visible(fn (WaCampaign $record) => $record->status === 'paused')
                    ->requiresConfirmation()->modalHeading(__('বাকি মেসেজ আবার পাঠানো শুরু করবেন?'))
                    ->modalDescription(fn (WaCampaign $record) => __('থেমেছিল: :error। সমস্যাটা ঠিক হয়ে থাকলে চালু করুন।', ['error' => $record->error ?? '—']))
                    ->action(function (WaCampaign $record) {
                        $record->update(['status' => 'queued', 'error' => null]);
                        Engine::kickWa($record->company_id);
                        Notification::make()->success()->title(__('আবার পাঠানো শুরু হয়েছে'))->send();
                    }),
                Action::make('cancel')->label(__('বাতিল'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (WaCampaign $record) => $record->open())
                    ->requiresConfirmation()->modalHeading(__('বাকি মেসেজ বাতিল করবেন?'))
                    ->modalDescription(__('যেগুলো এখনো যায়নি সেগুলো আর যাবে না। যেগুলো গেছে সেগুলো ফেরানো যায় না।'))
                    ->action(function (WaCampaign $record) {
                        $n = $record->messages()->where('status', 'queued')->update(['status' => 'cancelled', 'updated_at' => now()]);
                        if (! $record->messages()->whereIn('status', ['queued', 'sending'])->exists()) {
                            $record->update(['status' => 'cancelled', 'finished_at' => now()]);
                        }
                        Notification::make()->success()->title(__(':nটি মেসেজ বাতিল হয়েছে', ['n' => number_format($n)]))->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListWaCampaigns::route('/')];
    }
}
