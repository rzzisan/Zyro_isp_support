<?php

namespace App\Filament\App\Resources\SmsCampaigns;

use App\Filament\App\Resources\SmsCampaigns\Pages\ListSmsCampaigns;
use App\Filament\App\Resources\SmsMessages\SmsMessageResource;
use App\Models\SmsCampaign;
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

/** Every SMS send from the Desk with its progress; "SMS পাঠান" opens the form. */
class SmsCampaignResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = SmsCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    public static function getNavigationLabel(): string
    {
        return __('SMS পাঠান');
    }

    protected static ?string $modelLabel = 'SMS';

    public static function getPluralModelLabel(): string
    {
        return __('SMS পাঠান');
    }

    protected static ?string $slug = 'sms';

    protected static string|\UnitEnum|null $navigationGroup = 'SMS ও WhatsApp';

    protected static ?int $navigationSort = 1;

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
            ->defaultSort('created_at', 'desc')
            ->poll(fn () => static::getEloquentQuery()->whereIn('status', ['queued', 'sending'])->exists() ? '5s' : null)
            ->emptyStateHeading(__('এখনো কোনো SMS পাঠানো হয়নি'))
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('user.name')->label(__('কে'))->placeholder('—'),
                TextColumn::make('kind')->label(__('ধরন'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => ['bulk' => __('ফিল্টার'), 'bill' => __('বিল পরিশোধ'), 'ticket' => __('টিকিট'), 'reminder' => __('রিমাইন্ডার')][$state] ?? __('নির্দিষ্ট')),
                TextColumn::make('body')->label(__('লেখা'))->limit(80)->wrap()->searchable(),
                TextColumn::make('total')->label(__('জন'))->numeric(),
                TextColumn::make('sent')->label(__('গেছে'))
                    ->formatStateUsing(fn ($state, SmsCampaign $record) => number_format((int) $state).' / '.number_format($record->total))
                    ->description(fn (SmsCampaign $record) => $record->failed ? __(':nটি ব্যর্থ', ['n' => number_format($record->failed)]) : null),
                TextColumn::make('parts')->label('SMS')->numeric()->toggleable(),
                TextColumn::make('cost')->label(__('খরচ (আনুমানিক)'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).__(' টাকা'))->toggleable(),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => __(SmsCampaign::STATUSES[$state] ?? $state))
                    ->color(fn (string $state) => ['queued' => 'gray', 'sending' => 'info', 'done' => 'success', 'cancelled' => 'warning'][$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))->options(array_map('__', SmsCampaign::STATUSES)),
                SelectFilter::make('user_id')->label(__('কে'))->relationship('user', 'name'),
            ])
            ->recordActions([
                Action::make('messages')->label(__('SMS গুলো'))->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->url(fn (SmsCampaign $record) => SmsMessageResource::getUrl('index', ['filters' => ['campaign_id' => ['value' => $record->id]]])),
                Action::make('cancel')->label(__('বাতিল'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (SmsCampaign $record) => $record->open())
                    ->requiresConfirmation()->modalHeading(__('বাকি SMS বাতিল করবেন?'))
                    ->modalDescription(__('যেগুলো এখনো যায়নি সেগুলো আর যাবে না। যেগুলো গেছে সেগুলো ফেরানো যায় না।'))
                    ->action(function (SmsCampaign $record) {
                        $n = $record->messages()->where('status', 'queued')->update(['status' => 'cancelled', 'updated_at' => now()]);
                        if (! $record->messages()->whereIn('status', ['queued', 'sending'])->exists()) {
                            $record->update(['status' => 'cancelled', 'finished_at' => now()]);
                        }
                        Notification::make()->success()->title(__(':nটি SMS বাতিল হয়েছে', ['n' => number_format($n)]))->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListSmsCampaigns::route('/')];
    }
}
