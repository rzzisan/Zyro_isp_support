<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\Sms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** The company's SMS gateway (Khudebarta). Keys are stored encrypted and never shown again. Owner/Admin only. */
class SmsSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'SMS গেটওয়ে';

    protected static ?string $title = 'SMS গেটওয়ে';

    protected static ?string $slug = 'sms-gateway';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 6;

    protected function record(Company $company): Model
    {
        return SmsSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        /** @var SmsSetting $record */
        return ['endpoint_url' => $record->endpoint_url, 'balance_url' => $record->balance_url, 'provider_id' => $record->provider_id,
            'status_url' => $record->status_url, 'caller_id' => $record->caller_id, 'verify_tls' => $record->verify_tls,
            'enabled' => $record->enabled, 'rate' => $record->rate, 'portal_login_url' => $record->portal_login_url,
            'portal_username' => $record->portal_username, 'api_key' => null, 'secret_key' => null, 'portal_password' => null];
    }

    public function getSubheading(): ?string
    {
        return 'Desk-এর সব SMS এই গেটওয়ে দিয়ে যায় (বিলিং সফটওয়্যার দিয়ে নয়)। "SMS পাঠানো চালু" বন্ধ থাকলে SMS তালিকায় অপেক্ষা করে, কিছুই যায় না।';
    }

    public function form(Schema $schema): Schema
    {
        /** @var SmsSetting $s */
        $s = $this->record(Filament::getTenant());

        return $schema->components([
            TextInput::make('endpoint_url')->label('SMS পাঠানোর URL')->required()->url()->maxLength(250)
                ->helperText('Khudebarta-র send URL (পোর্ট সহ, যেমন …/sendtext); BSOL-এর অ্যাডমিন → SMS → Gateways-এ যেটা আছে'),
            Grid::make(2)->schema([
                TextInput::make('api_key')->label('API Key')->password()->revealable()->maxLength(200)
                    ->required(! filled($s->api_key))
                    ->helperText(filled($s->api_key) ? 'এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না'),
                TextInput::make('secret_key')->label('Secret Key')->password()->revealable()->maxLength(200)
                    ->required(! filled($s->secret_key))
                    ->helperText(filled($s->secret_key) ? 'এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না'),
                TextInput::make('caller_id')->label('Sender ID / Caller ID')->required()->maxLength(50)
                    ->helperText('Khudebarta-য় অনুমোদিত মাস্কিং নাম বা নম্বর'),
                TextInput::make('rate')->label('প্রতি SMS-এর দাম')->numeric()->minValue(0)->step(0.0001)->suffix('টাকা')
                    ->helperText('শুধু খরচের হিসাব দেখাতে (বাংলা SMS ৭০ অক্ষরে ১টা, ইংরেজি ১৬০-এ)'),
            ]),
            Section::make('ব্যালান্স (Khudebarta পোর্টাল লগইন) ও ডেলিভারি রিপোর্ট')->collapsible()->columns(2)->schema([
                TextInput::make('portal_username')->label('পোর্টাল ইউজারনেম')->maxLength(100)->placeholder('যেমন centurylinknetwork')
                    ->helperText('portal.khudebarta.com-এ যে ইউজারে লগইন করেন; ব্যালান্স দেখতে Desk এভাবে লগইন করে (৫ মিনিট পরপর ব্যালান্স)'),
                TextInput::make('portal_password')->label('পোর্টাল পাসওয়ার্ড')->password()->revealable()->maxLength(200)
                    ->helperText(filled($s->portal_password) ? 'এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে'),
                TextInput::make('portal_login_url')->label('পোর্টাল লগইন URL')->url()->maxLength(250),
                TextInput::make('balance_url')->label('পোর্টালের ব্যালান্স URL')->maxLength(250)
                    ->helperText('{provider_id} জায়গায় Provider ID বসে'),
                TextInput::make('provider_id')->label('Provider ID')->maxLength(50)
                    ->helperText('খালি রাখলে লগইনের userID নেওয়া হয় (Technical Details-এর providerID)'),
                TextInput::make('status_url')->label('ডেলিভারি রিপোর্ট URL (ঐচ্ছিক)')->url()->maxLength(250)
                    ->helperText('যেমন …/getstatus (messageid দিয়ে); দিলে "পৌঁছেছে" অবস্থা আসবে'),
            ]),
            Toggle::make('verify_tls')->label('SSL সার্টিফিকেট যাচাই')->default(true)
                ->helperText('গেটওয়ের সার্টিফিকেটের মেয়াদ শেষ হলে সংযোগ হবে না; তখন সাময়িকভাবে বন্ধ করা যায়'),
            Toggle::make('enabled')->label('SMS পাঠানো চালু')
                ->helperText('বন্ধ = কোনো SMS যায় না (তালিকায় অপেক্ষা করে)। চালুর আগে "সংযোগ পরীক্ষা" করে নিন।'),
            Placeholder::make('status')->label('শেষ পরীক্ষা')
                ->content(fn () => $s->balance_checked_at
                    ? ($s->last_check_ok ? '✅ সংযোগ ঠিক আছে'.($s->balance !== null ? ' · ব্যালান্স '.number_format((float) $s->balance, 2) : '')
                        : '❌ '.($s->last_error ?: 'সংযোগ হয়নি')).' · '.$s->balance_checked_at->diffForHumans()
                    : 'এখনো পরীক্ষা করা হয়নি'),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        foreach (['api_key', 'secret_key', 'portal_password'] as $k) {
            if (blank($data[$k] ?? null)) {
                unset($data[$k]);
            } else {
                $data[$k] = trim($data[$k]);
            }
        }
        foreach (['endpoint_url', 'balance_url', 'status_url', 'provider_id', 'portal_login_url', 'portal_username'] as $k) {
            $data[$k] = filled($data[$k] ?? null) ? trim($data[$k]) : null;
        }
        /** @var SmsSetting $record */
        if (isset($data['portal_password']) || $data['portal_username'] !== $record->portal_username
            || $data['portal_login_url'] !== $record->portal_login_url) {
            // new portal login: drop the old token and the failed-login pause
            $data += ['portal_token' => null, 'portal_refresh_token' => null, 'portal_token_expires_at' => null,
                'portal_login_failed_at' => null];
        }
        $data['caller_id'] = trim($data['caller_id']);
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }

    protected function afterSaved(Model $record): void
    {
        /** @var SmsSetting $record */
        if ($record->enabled) {
            Engine::kickSms($record->company_id);  // SMS waiting while it was off go now
        }
    }

    protected function getHeaderActions(): array
    {
        $s = fn () => SmsSetting::forCompany(Filament::getTenant());

        return [
            Action::make('testSms')->label('টেস্ট SMS পাঠান')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => $s()->configured())
                ->modalHeading('টেস্ট SMS')->modalSubmitActionLabel('পাঠান')
                ->modalDescription('এই নম্বরে এখনই একটা SMS যাবে ("SMS পাঠানো চালু" বন্ধ থাকলেও), গেটওয়ের উত্তর দেখানো হবে।')
                ->schema([
                    TextInput::make('mobile')->label('মোবাইল')->required()->tel()
                        ->rule(fn () => fn ($attr, $value, $fail) => Sms::mobile($value) ? null : $fail('সঠিক মোবাইল নম্বর দিন')),
                    TextInput::make('text')->label('লেখা')->required()->maxLength(300)->default('Desk থেকে টেস্ট SMS'),
                ])
                ->action(function (array $data) {
                    $cid = (int) Filament::getTenant()->getKey();
                    $campaign = Sms::queue($cid, auth()->id(), 'single', trim($data['text']), [], [Sms::mobile($data['mobile'])]);
                    try {
                        $r = Engine::sendSmsNow($cid, (int) $campaign->messages()->value('id'));
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('টেস্ট SMS যায়নি')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    $reply = trim(($r['status'] !== null ? 'Status '.$r['status'].' ' : '').($r['text'] ?? ''));
                    ($r['ok'] ?? false)
                        ? Notification::make()->success()->title('গেটওয়ে SMS নিয়েছে')->body($reply)->persistent()->send()
                        : Notification::make()->danger()->title('গেটওয়ে নেয়নি')->body($r['error'] ?? $reply)->persistent()->send();
                }),
            Action::make('test')->label('ব্যালান্স দেখুন')->icon(Heroicon::OutlinedSignal)->color('gray')
                ->visible(fn () => $s()->configured())
                ->action(function () {
                    try {
                        $r = Engine::smsBalance(Filament::getTenant()->getKey());
                        Notification::make()->success()->title('সংযোগ ঠিক আছে')->body('ব্যালান্স: '.($r['balance'] ?? '—'))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('সংযোগ হয়নি')->body($e->getMessage())->send();
                    }
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
