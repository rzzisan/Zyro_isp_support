<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\SipSetting;
use BackedEnum;
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
use Illuminate\Database\Eloquent\Model;

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
            'bot_max_seconds' => $record->bot_max_seconds ?? 180];
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
                ->description(__('কেউ নির্দিষ্ট সময়ের মধ্যে কল না ধরলে বট (Gemini Live) কল ধরে বাংলায় কথা বলে: কলারের নম্বর মিলিয়ে বিল, বকেয়া, লাইনের অবস্থা জানায়। পুরো কল রেকর্ড হয়, কল লগে শোনা ও পড়া যায়। ফ্রি Gemini key লাগে (AI key পাতায়)।'))
                ->schema([
                    Select::make('bot_mode')->label(__('বট'))->required()->selectablePlaceholder(false)->live()
                        ->options(['off' => __('বন্ধ'), 'test' => __('শুধু টেস্ট নম্বরের কলে'), 'live' => __('সব কলে')]),
                    TextInput::make('bot_delay')->label(__('কত সেকেন্ড বাজার পর ধরবে'))->required()->integer()->minValue(0)->maxValue(25)
                        ->suffix(__('সেকেন্ড'))->helperText(__('রিং গ্রুপের টাইমআউটের চেয়ে কম রাখুন')),
                    TextInput::make('bot_model')->label(__('Gemini Live মডেল'))->maxLength(80)->placeholder('gemini-3.1-flash-live-preview')
                        ->rule('regex:/^[a-z0-9.\-]+$/'),
                    TextInput::make('bot_max_seconds')->label(__('একটা কলে বট সর্বোচ্চ কত সেকেন্ড'))->required()->integer()
                        ->minValue(60)->maxValue(600)->suffix(__('সেকেন্ড'))
                        ->helperText(__('সময় শেষের আগে বট "আমাদের একজন স্টাফ যোগাযোগ করবেন" বলে কল শেষ করে, কল লগে ফ্লাগ থাকে')),
                    Textarea::make('bot_test_numbers')->label(__('টেস্ট নম্বর'))->rows(2)->columnSpanFull()
                        ->visible(fn ($get) => $get('bot_mode') === 'test')->required(fn ($get) => $get('bot_mode') === 'test')
                        ->placeholder('01712345678, 01812345678')->helperText(__('কমা বা নতুন লাইনে কয়েকটা মোবাইল নম্বর')),
                    Textarea::make('bot_blocked_numbers')->label(__('ব্লক করা নম্বর'))->rows(2)->columnSpanFull()
                        ->placeholder('01712345678')->helperText(__('এই নম্বরের কল বট কখনো ধরবে না, শুধু এজেন্টদের ফোনে বাজবে')),
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
}
