<?php

namespace App\Filament\App\Resources\VoiceReminderCalls;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\VoiceOptouts\VoiceOptoutResource;
use App\Filament\App\Resources\VoiceReminderCalls\Pages\ListVoiceReminderCalls;
use App\Models\VoiceReminderCall;
use App\Support\AltNumbers;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every voice reminder call: planned, waiting, done, with what happened (the scheduler's queue and its results). */
class VoiceReminderCallResource extends Resource
{
    use QuickAccessible;

    protected static ?string $model = VoiceReminderCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $slug = 'voice-reminder-calls';

    protected static string|\UnitEnum|null $navigationGroup = 'ভয়েস রিমাইন্ডার';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('রিমাইন্ডার কল');
    }

    public static function getModelLabel(): string
    {
        return __('রিমাইন্ডার কল');
    }

    public static function getPluralModelLabel(): string
    {
        return __('রিমাইন্ডার কল');
    }

    public static function canAccess(): bool
    {
        return Menu::can('voice_reminder');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $color = fn (?string $s) => match ($s) {
            'done' => 'success', 'queued', 'dialing', 'answered' => 'info', 'no_answer', 'busy', 'expired' => 'warning',
            'failed' => 'danger', default => 'gray',
        };

        return $table
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->emptyStateHeading(__('এখনো কোনো রিমাইন্ডার কল নেই'))
            ->columns([
                TextColumn::make('scheduled_at')->label(__('কখন'))->sortable()
                    ->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M, g:i A')),
                TextColumn::make('kind')->label(__('ধরন'))->badge()->formatStateUsing(fn ($state) => __(VoiceReminderCall::KINDS[$state] ?? $state)),
                TextColumn::make('name')->label(__('কাস্টমার'))->searchable(['name', 'customer_id', 'number'])
                    ->description(fn (VoiceReminderCall $r) => trim(($r->customer_id ? 'ID '.$r->customer_id.' · ' : '').'0'.substr((string) $r->number, 3)
                        .($r->alt_of ? ' · '.__('বিকল্প নম্বর').($r->alt_number && $r->alt_number !== $r->number ? ' 0'.substr($r->alt_number, 3) : '')
                            .' ('.collect(explode(',', (string) $r->number_source))->map(fn ($x) => __(AltNumbers::SOURCES[$x] ?? $x))->join(', ').')' : ''))),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()->color($color)
                    ->formatStateUsing(fn ($state) => __(VoiceReminderCall::STATUSES[$state] ?? $state))
                    ->description(fn (VoiceReminderCall $r) => $r->tries > 1 ? __(':n বার কল', ['n' => $r->tries]) : null),
                TextColumn::make('result')->label(__('ফলাফল'))->badge()->color('success')
                    ->formatStateUsing(fn ($state) => __(VoiceReminderCall::RESULTS[$state] ?? $state))
                    ->description(fn (VoiceReminderCall $r) => $r->keys ? __('বাটন: :k', ['k' => $r->keys]) : null),
                TextColumn::make('seconds')->label(__('সময়'))
                    ->formatStateUsing(fn ($state) => $state ? gmdate('i:s', (int) $state) : ''),
                TextColumn::make('reason')->label(__('কেন বাদ / সমস্যা'))->wrap()->color('gray')
                    ->state(fn (VoiceReminderCall $r) => collect([$r->reason ?: $r->error,
                        $r->backup_sms_id ? __('কলে পাওয়া যায়নি, বিল রিমাইন্ডার SMS গেছে') : null,
                        $r->sms_backup && $r->reached ? __('কলে জানানো হয়েছে, SMS লাগেনি') : null])->filter()->join(' · ')),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('ধরন'))->options(array_map('__', VoiceReminderCall::KINDS)),
                SelectFilter::make('status')->label(__('অবস্থা'))->options(array_map('__', VoiceReminderCall::STATUSES)),
                Filter::make('alt')->label(__('শুধু বিকল্প নম্বরের কল'))->query(fn (Builder $q) => $q->whereNotNull('alt_of')),
                Filter::make('today')->label(__('শুধু আজ'))->default()
                    ->query(fn (Builder $q) => $q->where('created_at', '>=', now('Asia/Dhaka')->startOfDay()->utc())),
            ])
            ->headerActions([
                Action::make('optouts')->label(__('"আর কল নয়" তালিকা'))->icon(Heroicon::OutlinedNoSymbol)->color('gray')
                    ->url(fn () => VoiceOptoutResource::getUrl()),
            ])
            ->recordActions([
                Action::make('cancel')->label(__('বাতিল'))->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->visible(fn (VoiceReminderCall $r) => $r->status === 'planned')
                    ->action(function (VoiceReminderCall $r) {
                        $r->update(['status' => 'skipped', 'reason' => __('বাতিল করেছেন :n', ['n' => auth()->user()->name])]);
                        Notification::make()->success()->title(__('এই কল যাবে না'))->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', (int) Filament::getTenant()?->getKey());
    }

    public static function getPages(): array
    {
        return ['index' => ListVoiceReminderCalls::route('/')];
    }
}
