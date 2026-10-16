<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Services\Engine;
use App\Support\BillReminders;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/** The company's SMS gateway (Khudebarta). Keys are stored encrypted and never shown again. Owner/Admin only. */
class SmsSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function getNavigationLabel(): string
    {
        return __('SMS গেটওয়ে');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('SMS গেটওয়ে');
    }

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
            'api_key' => null, 'secret_key' => null, 'reminder_days' => $record->reminder_days ?? '3',
            'reminder_hour' => $record->reminder_hour ?? 10, 'reminder_due_only' => $record->reminder_due_only ?? true]
            + static::eventData($record);
    }

    /** Events that send the customer an SMS from the Desk: template type => toggle label. */
    private const EVENTS = [
        'bill_paid' => 'বিল নিলে ("বিল নিন")',
        'ticket_open' => 'টিকিট খুললে',
        'ticket_solved' => 'টিকিট সমাধান হলে',
        'bill_reminder' => 'বিল রিমাইন্ডার (মেয়াদ শেষের আগে)',
    ];

    /** On/off and template of every event; a missing template defaults to the company's first one of that type. */
    private static function eventData(SmsSetting $record): array
    {
        $out = [];
        foreach (array_keys(self::EVENTS) as $type) {
            [$on, $tpl] = SmsTemplate::SETTINGS[$type];
            $out[$on] = (bool) $record->{$on};
            $out[$tpl] = $record->{$tpl} ?? array_key_first(SmsTemplate::optionsFor((int) $record->company_id, $type));
        }

        return $out;
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
            Section::make('কখন কাস্টমারকে নিজে থেকে SMS যাবে')->columns(2)
                ->description('চালু থাকলে ফর্মে "কাস্টমারকে SMS" ডিফল্টে চালু থাকে; প্রতিবার ফর্মে বন্ধ করা যায়। SMS যায় শুধু এই গেটওয়ে দিয়ে, বিলিং থেকে কখনো নয়। টেমপ্লেটের লেখা: SMS → SMS টেমপ্লেট।')
                ->schema(collect(static::EVENTS)->flatMap(fn ($label, $type) => [
                    Toggle::make(SmsTemplate::SETTINGS[$type][0])->label($label)->inline(false),
                    Select::make(SmsTemplate::SETTINGS[$type][1])->label('টেমপ্লেট')
                        ->options(fn () => SmsTemplate::optionsFor((int) Filament::getTenant()->getKey(), $type))
                        ->required(fn ($get) => (bool) $get(SmsTemplate::SETTINGS[$type][0])),
                ])->all()),
            Section::make('বিল রিমাইন্ডার')->columns(3)->collapsible()
                ->description('কাস্টমারের মেয়াদ শেষের তারিখ = বিলিংয়ের Billing Last Date (মাসের ওই দিন)। চালু থাকলে নিচের সময়ে প্রতিদিন যাদের মেয়াদ ঠিক অত দিন পরে শেষ, তারা "বিল রিমাইন্ডার" টেমপ্লেটের SMS পান; একই মেয়াদের একই দিনের রিমাইন্ডার কাউকে দুইবার যায় না। Left কাস্টমার আর বন্ধ লাইন বাদ।')
                ->schema([
                    TextInput::make('reminder_days')->label('কত দিন আগে')->required()->maxLength(30)
                        ->placeholder('যেমন 3 বা 3,1')->helperText('কমা দিয়ে কয়েকটা দিন; 0 = মেয়াদের দিনেই')
                        ->rule('regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'),
                    Select::make('reminder_hour')->label('কখন পাঠাবে')->required()->selectablePlaceholder(false)
                        ->options(collect(range(6, 22))->mapWithKeys(fn ($h) => [$h => Carbon::createFromTime($h)->format('g:00 A')])->all()),
                    Toggle::make('reminder_due_only')->label('শুধু বকেয়া থাকলে')->inline(false)
                        ->helperText('বন্ধ করলে বিল দেওয়া কাস্টমারও পাবেন'),
                    Placeholder::make('reminder_preview')->label('আগামীকাল কতজন পাবেন')->columnSpanFull()
                        ->content(function () {
                            $s = SmsSetting::forCompany(Filament::getTenant());
                            $counts = BillReminders::preview((int) Filament::getTenant()->getKey(), $s,
                                now(BillReminders::TZ)->addDay());

                            return $counts ? collect($counts)->map(fn ($n, $d) => ($d === 0 ? 'মেয়াদের দিনে' : "$d দিন আগে").': '.number_format($n).' জন')->implode(' · ')
                                .($s->eventOn('bill_reminder') ? '' : ' (রিমাইন্ডার এখন বন্ধ)') : '—';
                        }),
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
        $data['reminder_days'] = implode(',', BillReminders::days($data['reminder_days'] ?? '3')) ?: '3';
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
