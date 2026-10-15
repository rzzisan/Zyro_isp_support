<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Services\Engine;
use App\Support\Sms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
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

    /** "new-password" is the value Chrome respects; "off" alone is ignored for password fields. */
    private const NO_AUTOFILL = ['autocomplete' => 'new-password', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true'];

    protected function record(Company $company): Model
    {
        return SmsSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        /** @var SmsSetting $record */
        return ['endpoint_url' => $record->endpoint_url, 'status_url' => $record->status_url, 'caller_id' => $record->caller_id,
            'verify_tls' => $record->verify_tls, 'enabled' => $record->enabled, 'rate' => $record->rate,
            'bill_sms' => $record->bill_sms, 'bill_template_id' => $record->bill_template_id
                ?? array_key_first(SmsTemplate::billPaidOptions((int) $record->company_id)),
            'api_key' => null, 'secret_key' => null];
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
                ->helperText('Khudebarta HTTP API-র send URL, যেমন '.SmsSetting::KHUDEBARTA_SEND_URL.'। ব্যালান্স (/api/v3/balance), কানেকশন যাচাই (/validateclient) আর ডেলিভারি (/getstatus) একই ঠিকানায়'),
            Grid::make(2)->schema([
                // the browser would autofill the Desk login here; these are the gateway's own keys
                TextInput::make('api_key')->label('API Key')->password()->revealable()->maxLength(200)->extraInputAttributes(self::NO_AUTOFILL)
                    ->required(! filled($s->api_key))
                    ->helperText(filled($s->api_key) ? 'এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না'),
                TextInput::make('secret_key')->label('Secret Key')->password()->revealable()->maxLength(200)->extraInputAttributes(self::NO_AUTOFILL)
                    ->required(! filled($s->secret_key))
                    ->helperText(filled($s->secret_key) ? 'এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না'),
                TextInput::make('caller_id')->label('Sender ID / Caller ID')->required()->maxLength(50)
                    ->helperText('Khudebarta-য় অনুমোদিত মাস্কিং নাম বা নম্বর'),
                TextInput::make('rate')->label('প্রতি SMS-এর দাম')->numeric()->minValue(0)->step(0.0001)->suffix('টাকা')
                    ->helperText('শুধু খরচের হিসাব দেখাতে (বাংলা SMS ৭০ অক্ষরে ১টা, ইংরেজি ১৬০-এ)'),
            ]),
            TextInput::make('status_url')->label('ডেলিভারি রিপোর্ট URL (ঐচ্ছিক)')->url()->maxLength(250)
                ->placeholder('খালি = send URL-এর ঠিকানায় /getstatus')
                ->helperText('গেটওয়ে নেওয়া প্রতিটা SMS-এর অবস্থা (পৌঁছেছে / ব্যর্থ) ১০ মিনিট পরপর ৪৮ ঘণ্টা পর্যন্ত জিজ্ঞেস করা হয়'),
            Grid::make(2)->schema([
                Toggle::make('bill_sms')->label('বিল নিলে কাস্টমারকে SMS')
                    ->helperText('"বিল নিন" ফর্মে "কাস্টমারকে SMS" ডিফল্টে চালু থাকবে; প্রতিবার ফর্মে বন্ধ করা যায়। SMS যায় শুধু এই গেটওয়ে দিয়ে, বিলিং থেকে কখনো নয়।'),
                Select::make('bill_template_id')->label('বিল পরিশোধের টেমপ্লেট')
                    ->options(fn () => SmsTemplate::billPaidOptions((int) Filament::getTenant()->getKey()))
                    ->required(fn ($get) => (bool) $get('bill_sms'))
                    ->helperText('SMS → SMS টেমপ্লেট-এ "বিল পরিশোধ" ধরনের টেমপ্লেট; {paid} {discount} {due} বিল থেকে বসে'),
            ]),
            Toggle::make('verify_tls')->label('SSL সার্টিফিকেট যাচাই')->default(true)
                ->helperText('গেটওয়ের সার্টিফিকেটের মেয়াদ শেষ হলে সংযোগ হবে না; তখন সাময়িকভাবে বন্ধ করা যায়'),
            Toggle::make('enabled')->label('SMS পাঠানো চালু')
                ->helperText('বন্ধ = কোনো SMS যায় না (তালিকায় অপেক্ষা করে)। চালুর আগে "কানেকশন যাচাই" আর টেস্ট SMS করে নিন।'),
            Placeholder::make('status')->label('শেষ পরীক্ষা')
                ->content(fn () => $s->balance_checked_at
                    ? ($s->last_check_ok ? '✅ সংযোগ ঠিক আছে'.($s->balance !== null ? ' · ব্যালান্স '.number_format((float) $s->balance, 2) : '')
                        : '❌ '.($s->last_error ?: 'সংযোগ হয়নি')).' · '.$s->balance_checked_at->diffForHumans()
                    : 'এখনো পরীক্ষা করা হয়নি'),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        foreach (['api_key', 'secret_key'] as $k) {
            if (blank($data[$k] ?? null)) {
                unset($data[$k]);
            } else {
                $data[$k] = trim($data[$k]);
            }
        }
        foreach (['endpoint_url', 'status_url'] as $k) {
            $data[$k] = filled($data[$k] ?? null) ? trim($data[$k]) : null;
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
            Action::make('validate')->label('কানেকশন যাচাই')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                ->visible(fn () => $s()->configured())
                ->action(function () {
                    try {
                        $r = Engine::validateSms(Filament::getTenant()->getKey());
                        Notification::make()->success()->title('কানেকশন ঠিক আছে')
                            ->body(trim(($r['client'] ?? '').' · ব্যালান্স '.($r['balance'] ?? '—'), ' ·'))->persistent()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('কানেকশন হয়নি')->body($e->getMessage())->persistent()->send();
                    }
                    $this->redirect(static::getUrl());
                }),
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
                    $reply = trim(($r['status'] !== null ? 'Status '.$r['status'].' ' : '').($r['text'] ?? '').' '.($r['description'] ?? '')
                        .(! empty($r['message_id']) ? ' · Message ID '.$r['message_id'] : ''));
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
