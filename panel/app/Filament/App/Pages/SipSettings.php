<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\SipSetting;
use App\Services\PbxPortalClient;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The hotline's SIP extension (cloud PBX). Put it in the hotline's ring group: while a call rings, the panel shows
 * the caller and their customer profile. It only listens and never answers. Owner/Admin only.
 */
class SipSettings extends CompanySettingsPage
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    public static function getNavigationLabel(): string
    {
        return __('হটলাইন (SIP)');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('হটলাইন (SIP)');
    }

    protected static ?string $slug = 'hotline-sip';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 7;

    private const NO_AUTOFILL = ['autocomplete' => 'new-password', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true'];

    protected function record(Company $company): Model
    {
        return SipSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        /** @var SipSetting $record */
        return ['server' => $record->server, 'port' => $record->port ?? 5060, 'transport' => $record->transport ?? 'udp',
            'username' => $record->username, 'auth_user' => $record->auth_user, 'password' => null, 'enabled' => $record->enabled,
            'bot_mode' => $record->bot_mode ?? 'off', 'bot_delay' => $record->bot_delay ?? 20, 'bot_model' => $record->bot_model,
            'bot_test_numbers' => $record->bot_test_numbers, 'bot_blocked_numbers' => $record->bot_blocked_numbers,
            'bot_max_seconds' => $record->bot_max_seconds ?? 180,
            'bot_engine' => $record->bot_engine ?? 'live', 'cascade_scope' => $record->cascade_scope ?? 'test',
            'portal_url' => $record->portal_url, 'portal_username' => $record->portal_username, 'portal_password' => null];
    }

    public function form(Schema $schema): Schema
    {
        $s = $this->record(Filament::getTenant());

        return $schema->components([
            Placeholder::make('how')->hiddenLabel()
                ->content(__('PBX-এ একটা আলাদা এক্সটেনশন বানিয়ে হটলাইনের রিং গ্রুপে যোগ করুন, তারপর এখানে তার তথ্য দিন। কল এলে এটা শুধু বাজে, কখনো ধরে না; সেই সময় Desk-এ কলারের নম্বর আর কাস্টমার প্রোফাইল ভেসে ওঠে।')),
            Grid::make(3)->schema([
                TextInput::make('server')->label(__('SIP সার্ভার'))->required()->maxLength(200)
                    ->placeholder('pbx.example.com')
                    ->rule('regex:/^[A-Za-z0-9.\-]+$/')->validationMessages(['regex' => __('শুধু ডোমেইন বা IP দিন (sip: বা পোর্ট ছাড়া)')]),
                TextInput::make('port')->label(__('পোর্ট'))->required()->integer()->minValue(1)->maxValue(65535),
                Select::make('transport')->label(__('ট্রান্সপোর্ট'))->required()->selectablePlaceholder(false)
                    ->options(['udp' => 'UDP', 'tcp' => 'TCP']),
                TextInput::make('username')->label(__('এক্সটেনশন / ইউজারনেম'))->required()->maxLength(80)
                    ->rule('regex:/^[A-Za-z0-9._\-+]+$/'),
                TextInput::make('auth_user')->label(__('Auth ইউজার (ঐচ্ছিক)'))->maxLength(80)
                    ->placeholder(__('খালি = এক্সটেনশনই'))->rule('regex:/^[A-Za-z0-9._\-+@]+$/'),
                // the browser would autofill the Desk login here; this is the PBX extension's own password
                TextInput::make('password')->label(__('পাসওয়ার্ড'))->password()->revealable()->maxLength(120)->extraInputAttributes(self::NO_AUTOFILL)
                    ->required(! filled($s->password))
                    ->rule('regex:/^[^;<>\s"]+$/')->validationMessages(['regex' => __('পাসওয়ার্ডে ; < > " বা স্পেস থাকলে চলবে না; PBX-এ পাসওয়ার্ড বদলে নিন')])
                    ->helperText(filled($s->password) ? __('এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না')),
            ]),
            Toggle::make('enabled')->label(__('কল পপআপ চালু'))
                ->helperText(__('চালু করলে এক মিনিটের মধ্যে এক্সটেনশন রেজিস্টার হয়; বন্ধ করলে রেজিস্ট্রেশন ছেড়ে দেয়')),
            Section::make(__('ভয়েস বট'))->columns(3)
                ->description(__('কেউ নির্দিষ্ট সময়ের মধ্যে কল না ধরলে বট (Gemini Live) কল ধরে বাংলায় কথা বলে: কলারের নম্বর মিলিয়ে বিল, বকেয়া, লাইনের অবস্থা জানায়। পুরো কল রেকর্ড হয়, কল লগে শোনা ও পড়া যায়। Gemini key লাগে (AI key পাতায়)।'))
                ->schema([
                    Select::make('bot_mode')->label(__('বট'))->required()->selectablePlaceholder(false)->live()
                        ->options(['off' => __('বন্ধ'), 'test' => __('শুধু টেস্ট নম্বরের কলে'), 'live' => __('সব কলে')]),
                    TextInput::make('bot_delay')->label(__('কত সেকেন্ড বাজার পর ধরবে'))->required()->integer()->minValue(0)->maxValue(25)
                        ->suffix(__('সেকেন্ড'))->helperText(__('রিং গ্রুপের টাইমআউটের চেয়ে কম রাখুন')),
                    Select::make('bot_engine')->label(__('বট চালানোর পদ্ধতি'))->required()->selectablePlaceholder(false)->live()
                        ->options(['live' => __('Gemini Live (দ্রুত, খরচ বেশি)'), 'cascade' => __('লেখা-ভিত্তিক (সস্তা): শোনা → Claude → কণ্ঠ')])
                        ->helperText(__('লেখা-ভিত্তিক: প্রতি কলে খরচ প্রায় এক-তৃতীয়াংশ, উত্তরে ১-২ সেকেন্ড বেশি দেরি। Claude ও Gemini key লাগে; কোন কাজে কোন key তা AI key পাতায়।')),
                    Select::make('cascade_scope')->label(__('লেখা-ভিত্তিক কাদের কলে'))->required()->selectablePlaceholder(false)->live()
                        ->visible(fn ($get) => $get('bot_engine') === 'cascade')
                        ->options(['test' => __('শুধু টেস্ট নম্বরে (বাকিরা Gemini Live)'), 'all' => __('সব কলে')]),
                    Select::make('bot_model')->label(__('Gemini Live মডেল'))->placeholder(__('ডিফল্ট: gemini-3.1-flash-live-preview'))
                        ->options(fn ($get) => self::liveModels($get('bot_model')))
                        ->helperText(__('বদলালে পরের কল থেকে চলবে; ভালো না লাগলে ডিফল্টে ফিরিয়ে দিন')),
                    TextInput::make('bot_max_seconds')->label(__('একটা কলে বট সর্বোচ্চ কত সেকেন্ড'))->required()->integer()
                        ->minValue(60)->maxValue(600)->suffix(__('সেকেন্ড'))
                        ->helperText(__('সময় শেষের আগে বট "আমাদের একজন স্টাফ যোগাযোগ করবেন" বলে কল শেষ করে, কল লগে ফ্লাগ থাকে')),
                    Textarea::make('bot_test_numbers')->label(__('টেস্ট নম্বর'))->rows(2)->columnSpanFull()
                        ->visible(fn ($get) => $get('bot_mode') === 'test' || ($get('bot_engine') === 'cascade' && $get('cascade_scope') === 'test'))
                        ->required(fn ($get) => $get('bot_mode') === 'test' || ($get('bot_engine') === 'cascade' && $get('cascade_scope') === 'test'))
                        ->placeholder('01712345678, 01812345678')->helperText(__('কমা বা নতুন লাইনে কয়েকটা মোবাইল নম্বর')),
                    Textarea::make('bot_blocked_numbers')->label(__('ব্লক করা নম্বর'))->rows(2)->columnSpanFull()
                        ->placeholder('01712345678')->helperText(__('এই নম্বরের কল বট কখনো ধরবে না, শুধু এজেন্টদের ফোনে বাজবে')),
                ]),
            Section::make(__('PBX পোর্টাল (কল রেকর্ড রিপোর্টের জন্য)'))->columns(3)
                ->description(__('হটলাইনের ওয়েব পোর্টালের লগইন দিলে Desk পোর্টাল থেকে সব কলের রেকর্ড (CDR) এনে রাখে, তা দিয়ে হটলাইনের রিপোর্ট বানানো যায়। শুধু পড়ে, পোর্টালে কিছু বদলায় না। পাসওয়ার্ড এনক্রিপ্ট করে রাখা হয়, কাউকে দেখানো হয় না।'))
                ->schema([
                    TextInput::make('portal_url')->label(__('পোর্টালের ঠিকানা'))->maxLength(200)->placeholder('https://century.alphapbx.net')
                        ->rule('regex:/^(https?:\/\/)?[A-Za-z0-9.\-]+(:\d+)?\/?$/')->validationMessages(['regex' => __('শুধু পোর্টালের ঠিকানা দিন, যেমন https://century.alphapbx.net')]),
                    TextInput::make('portal_username')->label(__('পোর্টালের ইউজারনেম'))->maxLength(120)
                        ->required(fn ($get) => filled($get('portal_url')))->extraInputAttributes(['autocomplete' => 'off']),
                    TextInput::make('portal_password')->label(__('পোর্টালের পাসওয়ার্ড'))->password()->revealable()->maxLength(200)
                        ->extraInputAttributes(self::NO_AUTOFILL)
                        ->required(fn ($get) => filled($get('portal_url')) && ! filled($s->portal_password))
                        ->helperText(filled($s->portal_password) ? __('এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না')),
                    Placeholder::make('portal_status')->label(__('শেষ পরীক্ষা'))->columnSpanFull()
                        ->content(fn () => $s->portal_checked_at
                            ? ($s->portal_check_ok ? '✅ ' : '❌ ').$s->portal_check_message.' · '.$s->portal_checked_at->diffForHumans()
                            : __('এখনো পরীক্ষা করা হয়নি')),
                ]),
            Placeholder::make('status')->label(__('অবস্থা'))
                ->content(fn () => $s->status_at
                    ? ($s->status === 'registered' ? __('✅ রেজিস্টার্ড') : '❌ '.$s->status).' · '.$s->status_at->diffForHumans()
                    : __('এখনো রেজিস্টার হয়নি')),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        if (blank($data['portal_password'] ?? null)) {
            unset($data['portal_password']);
        }
        $data['portal_url'] = filled($data['portal_url'] ?? null) ? rtrim(trim($data['portal_url']), '/') : null;
        if ($data['portal_url'] && ! preg_match('#^https?://#i', $data['portal_url'])) {
            $data['portal_url'] = 'https://'.$data['portal_url'];
        }
        $data['portal_username'] = filled($data['portal_username'] ?? null) ? trim($data['portal_username']) : null;
        if (! $data['portal_url']) {   // cleared: forget the login too
            $data['portal_username'] = null;
            $data['portal_password'] = null;
        }
        $data['server'] = trim($data['server']);
        $data['username'] = trim($data['username']);
        $data['auth_user'] = filled($data['auth_user'] ?? null) ? trim($data['auth_user']) : null;
        $data['bot_model'] = filled($data['bot_model'] ?? null) ? trim($data['bot_model']) : null;
        // one number per line in the 01XXXXXXXXX form the engine compares with
        foreach (['bot_test_numbers', 'bot_blocked_numbers'] as $k) {
            $data[$k] = collect(preg_split('/[\s,]+/', (string) ($data[$k] ?? '')))
                ->map(fn ($n) => \App\Support\Sms::mobile($n))->filter()->map(fn ($n) => '0'.substr($n, 3))->unique()->implode("\n") ?: null;
        }
        $data['company_id'] = Filament::getTenant()->id;
        // the engine re-registers within a minute; the old status no longer applies
        $data['status'] = null;
        $data['status_at'] = null;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('portalTest')->label(__('পোর্টাল পরীক্ষা'))->icon(Heroicon::OutlinedSignal)->color('gray')
                ->visible(fn () => $this->record(Filament::getTenant())->portalConfigured())
                ->action(fn () => $this->testPortal()),
        ];
    }

    /** Logs into the PBX portal and counts today's call records; nothing is saved but the result. */
    public function testPortal(): void
    {
        abort_unless(static::canAccess(), 403);
        $s = $this->record(Filament::getTenant());
        try {
            $client = new PbxPortalClient($s->portal_url, $s->portal_username, $s->portal_password);
            $today = CarbonImmutable::now(PbxPortalClient::TZ)->startOfDay();
            $n = count($client->cdrs($today, $today->addDay()));
            $s->forceFill(['portal_checked_at' => now(), 'portal_check_ok' => true,
                'portal_check_message' => __('লগইন ঠিক আছে — আজ :n টা কল রেকর্ড', ['n' => $n])])->save();
            Notification::make()->success()->title(__('লগইন ঠিক আছে — আজ :n টা কল রেকর্ড', ['n' => $n]))->send();
        } catch (Throwable $e) {
            $s->forceFill(['portal_checked_at' => now(), 'portal_check_ok' => false,
                'portal_check_message' => mb_substr($e->getMessage(), 0, 300)])->save();
            Notification::make()->danger()->title(__('পোর্টালে ঢোকা যায়নি'))->body($e->getMessage())->send();
        }
        $this->redirect(static::getUrl());
    }

    /** Gemini Live models that opened with the bot's setup on the paid key (2026-10-25). Not listed: the 2.5 native-audio
     * models (reject languageCode bn-IN) and 3.8-live-extended-thinking (needs a thinking level). A model that fails to
     * open falls back to the default in the engine. A saved model not in the list stays selectable. */
    public const LIVE_MODELS = [
        'gemini-3.1-flash-live-preview' => 'gemini-3.1-flash-live-preview (ডিফল্ট)',
        'gemini-3.8-live' => 'gemini-3.8-live',
    ];

    public static function liveModels(?string $current): array
    {
        $out = self::LIVE_MODELS;
        if (filled($current) && ! isset($out[$current])) {
            $out[$current] = $current;
        }

        return $out;
    }
}
