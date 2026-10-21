<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\CallLog;
use App\Models\SipSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every hotline call the company's SIP extension saw ringing; the ones the voice bot answered (engine/voice_bot.py)
 * have the recording (both sides), the transcript and, when the bot promised it, why an agent should call back.
 */
class CallLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    public static function getNavigationLabel(): string
    {
        return __('কল লগ');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('হটলাইন কল লগ');
    }

    protected static ?string $slug = 'calls';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.app.call-logs';

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return \App\Support\Menu::can('calls') && $company && SipSetting::where('company_id', $company->getKey())->exists();
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(CallLog::query()->where('company_id', $company->getKey()))
            ->defaultSort('started_at', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('started_at')->label(__('সময়'))
                    ->formatStateUsing(fn ($state) => $state->timezone('Asia/Dhaka')->format('d M, g:i A')),
                TextColumn::make('caller')->label(__('নম্বর'))->searchable()
                    ->formatStateUsing(fn ($state, CallLog $record) => $record->caller_normalized ? '0'.substr($record->caller_normalized, 3) : $state),
                TextColumn::make('customer')->label(__('কাস্টমার'))->state(function (CallLog $record) {
                    $c = $record->customers(1)->first();

                    return $c ? "{$c->name} (ID {$c->customer_id})" : '—';
                })->url(function (CallLog $record) {
                    $c = $record->customers(1)->first();

                    return $c ? CustomerResource::getUrl('view', ['record' => $c]) : null;
                }),
                TextColumn::make('bot_answered')->label(__('ধরেছে'))->badge()
                    ->formatStateUsing(fn ($state, CallLog $record) => $state ? __('বট') : ($record->status === 'ringing' ? __('বাজছে') : __('এজেন্ট / মিসড')))
                    ->color(fn ($state) => $state ? 'info' : 'gray'),
                TextColumn::make('bot_seconds')->label(__('বটের সাথে কথা'))
                    ->formatStateUsing(fn ($state) => $state ? gmdate($state >= 3600 ? 'G:i:s' : 'i:s', (int) $state) : ''),
                TextColumn::make('callback_reason')->label(__('কল ব্যাক'))->wrap()->color('warning')->limit(80),
            ])
            ->filters([
                TernaryFilter::make('bot_answered')->label(__('বট ধরেছে')),
                TernaryFilter::make('callback')->label(__('কল ব্যাক লাগবে'))
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('callback_reason'), false: fn (Builder $q) => $q->whereNull('callback_reason')),
            ])
            ->recordActions([
                Action::make('listen')->label(__('শুনুন'))->icon(Heroicon::OutlinedPlay)->color('gray')
                    ->visible(fn (CallLog $record) => $record->bot_answered)
                    ->modalHeading(fn (CallLog $record) => __('কল: :n', ['n' => $record->caller]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ করুন'))
                    ->modalContent(fn (CallLog $record) => view('filament.app.call-log-detail', [
                        'call' => $record,
                        'audio' => $record->recording_path ? route('calls.recording', [$company, $record]) : null,
                    ])),
            ]);
    }
}
