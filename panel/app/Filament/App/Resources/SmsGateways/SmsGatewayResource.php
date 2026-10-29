<?php

namespace App\Filament\App\Resources\SmsGateways;

use App\Filament\App\Resources\SmsGateways\Pages\ManageSmsGateways;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\Sms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The company's SMS gateway accounts (Khudebarta, Bulk SMS Dhaka). Keys are encrypted and never shown again; which one
 * is the main one and which the fallback is chosen on the SMS gateway settings page. Owner/Admin only.
 */
class SmsGatewayResource extends Resource
{
    protected static ?string $model = SmsGateway::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $modelLabel = 'SMS গেটওয়ে';

    protected static ?string $pluralModelLabel = 'SMS গেটওয়ে তালিকা';

    protected static ?string $slug = 'sms-gateways';

    protected static bool $shouldRegisterNavigation = false;

    /** "new-password" is the value Chrome respects; "off" alone is ignored for password fields. */
    private const NO_AUTOFILL = ['autocomplete' => 'new-password', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true'];

    public static function getModelLabel(): string
    {
        return __('SMS গেটওয়ে');
    }

    public static function getPluralModelLabel(): string
    {
        return __('SMS গেটওয়ে তালিকা');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    public static function form(Schema $schema): Schema
    {
        $kept = fn (string $operation) => $operation === 'edit' ? __('এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না');
        $secret = fn (Get $get) => (bool) (SmsGateway::PROVIDERS[$get('provider')][2] ?? false);

        return $schema->components([
            Grid::make(2)->schema([
                Select::make('provider')->label(__('গেটওয়ে'))->options(SmsGateway::providerOptions())->required()
                    ->default('bulksmsdhaka')->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('endpoint_url', SmsGateway::PROVIDERS[$state][1] ?? null)),
                TextInput::make('label')->label(__('নাম (ঐচ্ছিক)'))->maxLength(60)->placeholder(__('যেমন Bulk SMS Dhaka মূল')),
            ]),
            TextInput::make('endpoint_url')->label(__('API URL'))->required()->url()->maxLength(250)
                ->default(SmsGateway::PROVIDERS['bulksmsdhaka'][1])
                ->dehydrateStateUsing(fn ($state) => trim((string) $state))
                ->helperText(fn (Get $get) => $get('provider') === 'khudebarta'
                    ? __('Khudebarta HTTP API-র send URL। ব্যালান্স, কানেকশন যাচাই আর ডেলিভারি একই ঠিকানায়')
                    : __('Bulk SMS Dhaka-র API ঠিকানা (sendtext, getBalance, getstatus এর আগের অংশ)')),
            Grid::make(2)->schema([
                // the browser would autofill the Desk login here; these are the gateway's own keys
                TextInput::make('api_key')->label('API Key')->password()->revealable()->maxLength(200)->extraInputAttributes(self::NO_AUTOFILL)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))->dehydrateStateUsing(fn ($state) => trim((string) $state))
                    ->helperText($kept),
                TextInput::make('secret_key')->label('Secret Key')->password()->revealable()->maxLength(200)->extraInputAttributes(self::NO_AUTOFILL)
                    ->visible($secret)
                    ->required(fn (string $operation, Get $get) => $operation === 'create' && $secret($get))
                    ->dehydrated(fn ($state) => filled($state))->dehydrateStateUsing(fn ($state) => trim((string) $state))
                    ->helperText($kept),
                TextInput::make('caller_id')->label('Sender ID / Caller ID')->required()->maxLength(50)
                    ->dehydrateStateUsing(fn ($state) => trim((string) $state))
                    ->helperText(__('গেটওয়েতে অনুমোদিত মাস্কিং নাম বা নম্বর')),
                TextInput::make('rate')->label(__('প্রতি SMS-এর দাম'))->numeric()->minValue(0)->step(0.0001)->suffix(__('টাকা'))
                    ->helperText(__('খরচের হিসাবের জন্য (বাংলা SMS ৭০ অক্ষরে ১টা, ইংরেজি ১৬০-এ)')),
                TextInput::make('low_balance')->label(__('ব্যালান্স এর নিচে নামলে সতর্ক করুন'))->numeric()->minValue(0)->suffix(__('টাকা'))
                    ->helperText(__('খালি = সতর্কতা নেই')),
                TextInput::make('status_url')->label(__('ডেলিভারি রিপোর্ট URL (ঐচ্ছিক)'))->url()->maxLength(250)
                    ->visible($secret)->placeholder(__('খালি = send URL-এর ঠিকানায় /getstatus')),
            ]),
            Toggle::make('verify_tls')->label(__('SSL সার্টিফিকেট যাচাই'))->default(true)
                ->helperText(__('গেটওয়ের সার্টিফিকেটের মেয়াদ শেষ হলে সংযোগ হবে না; তখন সাময়িকভাবে বন্ধ করা যায়')),
        ]);
    }

    public static function table(Table $table): Table
    {
        $settings = fn () => SmsSetting::forCompany(Filament::getTenant());

        return $table
            ->description(__('Bulk SMS Dhaka-য় IP whitelist চালু থাকলে তাদের ড্যাশবোর্ড → Setting → Ip White List-এ Desk সার্ভারের IP যোগ করুন, নাহলে "IP not whitelisted" আসবে।'))
            ->columns([
                TextColumn::make('label')->label(__('গেটওয়ে'))->state(fn (SmsGateway $r) => $r->name())
                    ->description(fn (SmsGateway $r) => $r->label ? $r->providerLabel() : null),
                TextColumn::make('role')->label(__('ভূমিকা'))->badge()
                    ->state(fn (SmsGateway $r) => match ($r->id) {
                        $settings()->primary_gateway_id => __('মূল'),
                        $settings()->fallback_gateway_id => __('ব্যাকআপ'),
                        default => __('ব্যবহার হচ্ছে না'),
                    })
                    ->color(fn ($state) => $state === __('মূল') ? 'success' : ($state === __('ব্যাকআপ') ? 'info' : 'gray')),
                TextColumn::make('caller_id')->label('Sender ID'),
                TextColumn::make('rate')->label(__('দাম/SMS'))->placeholder('—'),
                TextColumn::make('balance')->label(__('ব্যালান্স'))
                    ->state(fn (SmsGateway $r) => $r->balance === null ? '—' : number_format((float) $r->balance, 2)
                        .($r->balance_sms !== null ? ' · '.__(':n SMS', ['n' => number_format($r->balance_sms)]) : ''))
                    ->color(fn (SmsGateway $r) => $r->lowBalance() ? 'danger' : null)
                    ->description(fn (SmsGateway $r) => $r->lowBalance() ? __('ব্যালান্স কম, রিচার্জ করুন') : $r->balance_checked_at?->diffForHumans()),
                TextColumn::make('last_check_ok')->label(__('অবস্থা'))->wrap()
                    ->state(fn (SmsGateway $r) => match (true) {
                        $r->failed_at !== null => '⛔ '.__('থামানো').': '.$r->last_error,
                        $r->last_check_ok === null => __('এখনো পরীক্ষা করা হয়নি'),
                        $r->last_check_ok => '✅ '.__('সংযোগ ঠিক আছে'),
                        default => '❌ '.($r->last_error ?: __('সংযোগ হয়নি')),
                    }),
            ])
            ->headerActions([CreateAction::make()->label(__('গেটওয়ে যোগ করুন'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])
                ->after(function (SmsGateway $record) {
                    $s = SmsSetting::forCompany($record->company_id);
                    if (! $s->primary_gateway_id) {           // the first gateway becomes the main one
                        $s->fill(['primary_gateway_id' => $record->id])->save();
                    }
                    static::validateGateway($record);
                })])
            ->recordActions([
                Action::make('validate')->label(__('কানেকশন যাচাই'))->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                    ->action(fn (SmsGateway $record) => static::validateGateway($record)),
                Action::make('testSms')->label(__('টেস্ট SMS'))->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (SmsGateway $record) => $record->configured())
                    ->modalHeading(__('টেস্ট SMS'))->modalSubmitActionLabel(__('পাঠান'))
                    ->modalDescription(__('এই নম্বরে এখনই এই গেটওয়ে দিয়ে একটা SMS যাবে ("SMS পাঠানো চালু" বন্ধ থাকলেও), গেটওয়ের উত্তর দেখানো হবে।'))
                    ->schema([
                        TextInput::make('mobile')->label(__('মোবাইল'))->required()->tel()
                            ->rule(fn () => fn ($attr, $value, $fail) => Sms::mobile($value) ? null : $fail(__('সঠিক মোবাইল নম্বর দিন'))),
                        TextInput::make('text')->label(__('লেখা'))->required()->maxLength(300)->default(__('Desk থেকে টেস্ট SMS')),
                    ])
                    ->action(fn (SmsGateway $record, array $data) => static::testSms($record, $data)),
                Action::make('reactivate')->label(__('আবার চালু'))->icon(Heroicon::OutlinedArrowPath)->color('warning')
                    ->visible(fn (SmsGateway $record) => $record->failed_at !== null)
                    ->requiresConfirmation()
                    ->modalDescription(__('সমস্যা ঠিক করেছেন (রিচার্জ, key, IP whitelist)? এই গেটওয়ে আবার ব্যবহার হবে; মূল হলে এরপরের SMS এটা দিয়েই যাবে।'))
                    ->action(function (SmsGateway $record) {
                        $record->update(['failed_at' => null, 'last_error' => null]);
                        Engine::kickSms($record->company_id);
                        Notification::make()->success()->title(__('গেটওয়ে আবার চালু হয়েছে'))->send();
                    }),
                EditAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'failed_at' => null]),
                DeleteAction::make(),
            ]);
    }

    public static function validateGateway(SmsGateway $record): void
    {
        try {
            $r = Engine::validateSms($record->company_id, $record->id);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('কানেকশন হয়নি'))->body($e->getMessage())->persistent()->send();

            return;
        }
        $body = collect([$r['client'] ?? null,
            ($r['balance'] ?? null) !== null ? __('ব্যালান্স :amount', ['amount' => $r['balance']]) : null,
            ($r['sms'] ?? null) !== null ? __(':n SMS', ['n' => $r['sms']]) : null])->filter()->implode(' · ');
        Notification::make()->success()->title(__('কানেকশন ঠিক আছে'))->body($body ?: null)->persistent()->send();
    }

    public static function testSms(SmsGateway $record, array $data): void
    {
        $campaign = Sms::queue($record->company_id, auth()->id(), 'single', trim($data['text']), [], [Sms::mobile($data['mobile'])]);
        try {
            $r = Engine::sendSmsNow($record->company_id, (int) $campaign->messages()->value('id'), $record->id);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('টেস্ট SMS যায়নি'))->body($e->getMessage())->persistent()->send();

            return;
        }
        $reply = trim(($r['status'] !== null ? 'Status '.$r['status'].' ' : '').($r['text'] ?? '').' '.($r['description'] ?? '')
            .(! empty($r['message_id']) ? ' · Message ID '.$r['message_id'] : ''));
        ($r['ok'] ?? false)
            ? Notification::make()->success()->title(__('গেটওয়ে SMS নিয়েছে'))->body($reply)->persistent()->send()
            : Notification::make()->danger()->title(__('গেটওয়ে নেয়নি'))->body($r['error'] ?? $reply)->persistent()->send();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSmsGateways::route('/')];
    }
}
