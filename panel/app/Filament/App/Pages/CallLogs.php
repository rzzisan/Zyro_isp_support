<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\CallLog;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every hotline call the company's SIP extension saw ringing and every WhatsApp call (channel whatsapp); the ones the voice bot answered (engine/voice_bot.py)
 * have the recording (both sides), the transcript and, when the bot promised it, why an agent should call back.
 */
class CallLogs extends Page implements HasTable
{
    use InteractsWithTable;
    use QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    public static function getNavigationLabel(): string
    {
        return __('কল লগ');
    }

    public function getTitle(): string|Htmlable
    {
        return __('কল লগ');
    }

    protected static ?string $slug = 'calls';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.app.call-logs';

    /** why the bot handed the call over (engine/voice_bot.py FLAGS) */
    public const FLAGS = [
        'off_topic' => 'অপ্রাসঙ্গিক কথা',
        'wants_person' => 'মানুষ চেয়েছেন',
        'unsolved' => 'সমাধান হয়নি',
        'time_up' => 'সময় শেষ',
    ];

    /** the bot call's result (engine/voice_bot.py outcome): label and badge colour */
    public const OUTCOMES = [
        'solved' => ['সমাধান', 'success'],
        'info' => ['তথ্য দেওয়া', 'success'],
        'ticket' => ['টিকেট খোলা', 'info'],
        'open_ticket' => ['আগের টিকেট জানানো', 'info'],
        'callback' => ['কল ব্যাক দরকার', 'warning'],
        'unclear' => ['বোঝা যায়নি', 'danger'],
        'hung_up' => ['কলার কেটেছেন', 'gray'],
        // voice reminder calls (engine/voice_ivr.py)
        'listened' => ['রিমাইন্ডার শুনেছেন', 'success'],
        'sms' => ['পেমেন্ট SMS নিয়েছেন', 'success'],
        'reconnected' => ['লাইন চালু করেছেন', 'success'],
        'reconnect_failed' => ['লাইন চালু হয়নি', 'warning'],
        'staff' => ['স্টাফের সাথে কথা', 'info'],
        'voicemail' => ['ভয়েস মেসেজ রেখেছেন', 'warning'],
        'optout' => ['আর কল চান না', 'danger'],
        'no_answer' => ['ধরেননি', 'gray'],
    ];

    /** Call-backs nobody has done yet. */
    public static function getNavigationBadge(): ?string
    {
        $company = Filament::getTenant();
        $n = $company ? CallLog::where('company_id', $company->getKey())->callbackPending()->count() : 0;

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return Menu::can('calls') && $company && CallLog::enabledFor($company->getKey());
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
                TextColumn::make('channel')->label(__('লাইন'))->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'whatsapp' => 'WhatsApp', 'reminder' => __('রিমাইন্ডার'), default => __('হটলাইন')
                    })
                    ->color(fn ($state) => match ($state) {
                        'whatsapp' => 'success', 'reminder' => 'warning', default => 'gray'
                    }),
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
                    ->formatStateUsing(fn ($state, CallLog $record) => match (true) {
                        $record->direction === 'out' => __('আউটগোয়িং: :n', ['n' => $record->answeredBy?->name ?? '—']),
                        (bool) $state => __('বট'),
                        (bool) $record->answered_by => $record->answeredBy?->name ?? __('স্টাফ'),
                        in_array($record->status, ['ringing', 'answered'], true) => __('চলছে'),
                        $record->channel === 'whatsapp' => __('মিসড'),
                        default => __('এজেন্ট / মিসড'),
                    })
                    ->color(fn ($state, CallLog $record) => $state ? 'info' : ($record->answered_by ? 'success' : 'gray')),
                TextColumn::make('bot_seconds')->label(__('কথা'))
                    ->state(fn (CallLog $record) => $record->bot_seconds ?: $record->agent_seconds)
                    ->formatStateUsing(fn ($state) => $state ? gmdate($state >= 3600 ? 'G:i:s' : 'i:s', (int) $state) : ''),
                TextColumn::make('outcome')->label(__('ফলাফল'))->badge()
                    ->formatStateUsing(fn ($state) => __(self::OUTCOMES[$state][0] ?? $state))
                    ->color(fn ($state) => self::OUTCOMES[$state][1] ?? 'gray')
                    ->tooltip(fn (CallLog $record) => $record->outcome_text),
                TextColumn::make('bot_flag')->label(__('ফ্লাগ'))->badge()->color('danger')
                    ->formatStateUsing(fn ($state) => __(self::FLAGS[$state] ?? $state)),
                TextColumn::make('callback_reason')->label(__('কল ব্যাক'))->wrap()->limit(80)
                    ->color(fn (CallLog $record) => $record->callback_done_at ? 'gray' : 'warning')
                    ->description(fn (CallLog $record) => $record->callback_done_at
                        ? ($record->callback_done_by ? __('✓ কল ব্যাক করেছেন :n, :t', ['n' => $record->callbackDoneBy?->name ?? '', 't' => $record->callback_done_at->timezone('Asia/Dhaka')->format('d M, g:i A')]) : __('✓ পুরনো, তালিকা থেকে সরানো'))
                        : null),
            ])
            ->filters([
                SelectFilter::make('channel')->label(__('লাইন'))
                    ->options(['hotline' => __('হটলাইন'), 'whatsapp' => 'WhatsApp', 'reminder' => __('রিমাইন্ডার')]),
                TernaryFilter::make('bot_answered')->label(__('বট ধরেছে')),
                SelectFilter::make('outcome')->label(__('ফলাফল'))
                    ->options(collect(self::OUTCOMES)->map(fn ($o) => __($o[0]))->all()),
                TernaryFilter::make('callback')->label(__('কল ব্যাক বাকি'))
                    ->queries(true: fn (Builder $q) => $q->callbackPending(), false: fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('callback_reason')->orWhereNotNull('callback_done_at'))),
            ])
            ->recordActions([
                Action::make('callbackDone')->label(__('কল ব্যাক হয়েছে'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CallLog $record) => filled($record->callback_reason) && ! $record->callback_done_at)
                    ->action(function (CallLog $record) {
                        $record->update(['callback_done_at' => now(), 'callback_done_by' => auth()->id()]);
                        Notification::make()->title(__('কল ব্যাক তালিকা থেকে সরানো হয়েছে'))->success()->send();
                    }),
                Action::make('listen')->label(__('শুনুন'))->icon(Heroicon::OutlinedPlay)->color('gray')
                    ->visible(fn (CallLog $record) => $record->bot_answered || filled($record->recording_path))
                    ->modalHeading(fn (CallLog $record) => __('কল: :n', ['n' => $record->caller]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ করুন'))
                    ->modalContent(fn (CallLog $record) => view('filament.app.call-log-detail', [
                        'call' => $record,
                        'audio' => $record->recording_path ? route('calls.recording', [$company, $record]) : null,
                    ])),
            ]);
    }
}
