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

    protected static string|\UnitEnum|null $navigationGroup = 'SMS';

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
            ->emptyStateHeading('এখনো কোনো SMS পাঠানো হয়নি')
            ->columns([
                TextColumn::make('created_at')->label('সময়')->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('user.name')->label('কে')->placeholder('—'),
                TextColumn::make('kind')->label('ধরন')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => ['bulk' => 'ফিল্টার', 'bill' => 'বিল পরিশোধ', 'ticket' => 'টিকিট', 'reminder' => 'রিমাইন্ডার'][$state] ?? 'নির্দিষ্ট'),
                TextColumn::make('body')->label('লেখা')->limit(80)->wrap()->searchable(),
                TextColumn::make('total')->label('জন')->numeric(),
                TextColumn::make('sent')->label('গেছে')
                    ->formatStateUsing(fn ($state, SmsCampaign $record) => number_format((int) $state).' / '.number_format($record->total))
                    ->description(fn (SmsCampaign $record) => $record->failed ? number_format($record->failed).'টি ব্যর্থ' : null),
                TextColumn::make('parts')->label('SMS')->numeric()->toggleable(),
                TextColumn::make('cost')->label('খরচ (আনুমানিক)')->placeholder('—')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' টাকা')->toggleable(),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->formatStateUsing(fn (string $state) => SmsCampaign::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['queued' => 'gray', 'sending' => 'info', 'done' => 'success', 'cancelled' => 'warning'][$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('অবস্থা')->options(SmsCampaign::STATUSES),
                SelectFilter::make('user_id')->label('কে')->relationship('user', 'name'),
            ])
            ->recordActions([
                Action::make('messages')->label('SMS গুলো')->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->url(fn (SmsCampaign $record) => SmsMessageResource::getUrl('index', ['filters' => ['campaign_id' => ['value' => $record->id]]])),
                Action::make('cancel')->label('বাতিল')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (SmsCampaign $record) => $record->open())
                    ->requiresConfirmation()->modalHeading('বাকি SMS বাতিল করবেন?')
                    ->modalDescription('যেগুলো এখনো যায়নি সেগুলো আর যাবে না। যেগুলো গেছে সেগুলো ফেরানো যায় না।')
                    ->action(function (SmsCampaign $record) {
                        $n = $record->messages()->where('status', 'queued')->update(['status' => 'cancelled', 'updated_at' => now()]);
                        if (! $record->messages()->whereIn('status', ['queued', 'sending'])->exists()) {
                            $record->update(['status' => 'cancelled', 'finished_at' => now()]);
                        }
                        Notification::make()->success()->title(number_format($n).'টি SMS বাতিল হয়েছে')->send();
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
