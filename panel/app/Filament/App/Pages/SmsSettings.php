<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\SmsGateways\SmsGatewayResource;
use App\Models\Company;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Services\Engine;
use App\Support\BillReminders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The company's SMS settings: main and fallback gateway (the accounts and their keys: SmsGatewayResource), sending
 * on/off, the automatic SMS and the bill reminder. Owner/Admin only.
 */
class SmsSettings extends CompanySettingsPage
{
    use \App\Filament\App\Concerns\QuickAccessible;

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

    protected function record(Company $company): Model
    {
        return SmsSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        /** @var SmsSetting $record */
        return ['primary_gateway_id' => $record->primary_gateway_id, 'fallback_gateway_id' => $record->fallback_gateway_id,
            'enabled' => $record->enabled, 'reminder_days' => $record->reminder_days ?? '3',
            'reminder_hour' => $record->reminder_hour ?? 10, 'reminder_due_only' => $record->reminder_due_only ?? true]
            + static::eventData($record);
    }

    /** Events that send the customer an SMS from the Desk: template type => toggle label. */
    public const EVENTS = [
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
        return __('Desk-এর সব SMS এই গেটওয়ে দিয়ে যায় (বিলিং সফটওয়্যার দিয়ে নয়)। "SMS পাঠানো চালু" বন্ধ থাকলে SMS তালিকায় অপেক্ষা করে, কিছুই যায় না।');
    }

    public function form(Schema $schema): Schema
    {
        /** @var SmsSetting $s */
        $s = $this->record(Filament::getTenant());
        $gateways = fn () => SmsGateway::where('company_id', Filament::getTenant()->getKey())->orderBy('id')->get()
            ->mapWithKeys(fn (SmsGateway $g) => [$g->id => $g->name().' ('.$g->caller_id.')'])->all();

        return $schema->components([
            Section::make(__('গেটওয়ে'))->columns(2)
                ->description(__('সব SMS মূল গেটওয়ে দিয়ে যায়। মূল গেটওয়ে অ্যাকাউন্টের কারণে ফিরিয়ে দিলে (ব্যালান্স কম, key ভুল, IP whitelist নেই) সেটা থামানো হয় আর বাকি SMS ব্যাকআপ দিয়ে যায়। টাইমআউট বা একটা নম্বরের সমস্যায় ব্যাকআপে যায় না, তাই কেউ একই SMS দুইবার পায় না।'))
                ->schema([
                    Select::make('primary_gateway_id')->label(__('মূল গেটওয়ে'))->options($gateways)->required(fn () => filled($gateways()))
                        ->helperText(fn () => blank($gateways()) ? __('আগে "গেটওয়ে তালিকা" থেকে একটা গেটওয়ে যোগ করুন') : null),
                    Select::make('fallback_gateway_id')->label(__('ব্যাকআপ গেটওয়ে (ঐচ্ছিক)'))->options($gateways)
                        ->placeholder(__('নেই'))->different('primary_gateway_id'),
                    Placeholder::make('status')->label(__('এখন যাচ্ছে'))->columnSpanFull()
                        ->content(function () use ($s) {
                            $g = $s->activeGateway();
                            if (! $g) {
                                return __('কোনো গেটওয়ে সেট করা হয়নি');
                            }
                            $state = match (true) {
                                $g->failed_at !== null => '⛔ '.__('থামানো').': '.$g->last_error,
                                $g->balance_checked_at === null => __('এখনো পরীক্ষা করা হয়নি'),
                                $g->last_check_ok => '✅ '.__('সংযোগ ঠিক আছে').($g->balance !== null ? ' · '.__('ব্যালান্স :amount', ['amount' => number_format((float) $g->balance, 2)]) : '')
                                    .($g->lowBalance() ? ' · '.__('ব্যালান্স কম, রিচার্জ করুন') : ''),
                                default => '❌ '.($g->last_error ?: __('সংযোগ হয়নি')),
                            };

                            $off = ! $s->enabled && filled($s->last_error) ? '⛔ '.$s->last_error.' · ' : '';

                            return $off.$g->name().' · '.$state.($g->id !== $s->primary_gateway_id ? ' · '.__('(মূল গেটওয়ে থেমে আছে, ব্যাকআপ দিয়ে যাচ্ছে)') : '');
                        }),
                ]),
            Section::make(__('কখন কাস্টমারকে নিজে থেকে SMS যাবে'))->columns(2)
                ->description(__('চালু থাকলে ফর্মে "কাস্টমারকে SMS" ডিফল্টে চালু থাকে; প্রতিবার ফর্মে বন্ধ করা যায়। SMS যায় শুধু এই গেটওয়ে দিয়ে, বিলিং থেকে কখনো নয়। টেমপ্লেটের লেখা: SMS → SMS টেমপ্লেট।'))
                ->schema(collect(static::EVENTS)->flatMap(fn ($label, $type) => [
                    Toggle::make(SmsTemplate::SETTINGS[$type][0])->label(__($label))->inline(false),
                    Select::make(SmsTemplate::SETTINGS[$type][1])->label(__('টেমপ্লেট'))
                        ->options(fn () => SmsTemplate::optionsFor((int) Filament::getTenant()->getKey(), $type))
                        ->required(fn ($get) => (bool) $get(SmsTemplate::SETTINGS[$type][0])),
                ])->all()),
            Section::make(__('বিল রিমাইন্ডার'))->columns(3)->collapsible()
                ->description(__('কাস্টমারের মেয়াদ শেষের তারিখ = বিলিংয়ের Billing Last Date (মাসের ওই দিন)। চালু থাকলে নিচের সময়ে প্রতিদিন যাদের মেয়াদ ঠিক অত দিন পরে শেষ, তারা "বিল রিমাইন্ডার" টেমপ্লেটের SMS পান; একই মেয়াদের একই দিনের রিমাইন্ডার কাউকে দুইবার যায় না। Left কাস্টমার আর বন্ধ লাইন বাদ।'))
                ->schema([
                    TextInput::make('reminder_days')->label(__('কত দিন আগে'))->required()->maxLength(30)
                        ->placeholder(__('যেমন 3 বা 3,1'))->helperText(__('কমা দিয়ে কয়েকটা দিন; 0 = মেয়াদের দিনেই'))
                        ->rule('regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'),
                    Select::make('reminder_hour')->label(__('কখন পাঠাবে'))->required()->selectablePlaceholder(false)
                        ->options(collect(range(6, 22))->mapWithKeys(fn ($h) => [$h => Carbon::createFromTime($h)->format('g:00 A')])->all()),
                    Toggle::make('reminder_due_only')->label(__('শুধু বকেয়া থাকলে'))->inline(false)
                        ->helperText(__('বন্ধ করলে বিল দেওয়া কাস্টমারও পাবেন')),
                    Placeholder::make('reminder_preview')->label(__('আগামীকাল কতজন পাবেন'))->columnSpanFull()
                        ->content(function () {
                            $s = SmsSetting::forCompany(Filament::getTenant());
                            $counts = BillReminders::preview((int) Filament::getTenant()->getKey(), $s,
                                now(BillReminders::TZ)->addDay());

                            return $counts ? collect($counts)->map(fn ($n, $d) => ($d === 0 ? __('মেয়াদের দিনে') : __(':n দিন আগে', ['n' => $d])).': '.__(':n জন', ['n' => number_format($n)]))->implode(' · ')
                                .($s->eventOn('bill_reminder') ? '' : __(' (রিমাইন্ডার এখন বন্ধ)')) : '—';
                        }),
                ]),
            Toggle::make('enabled')->label(__('SMS পাঠানো চালু'))
                ->helperText(__('বন্ধ = কোনো SMS যায় না (তালিকায় অপেক্ষা করে)। চালুর আগে "কানেকশন যাচাই" আর টেস্ট SMS করে নিন।')),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        $own = SmsGateway::where('company_id', Filament::getTenant()->id)->pluck('id')->all();
        foreach (['primary_gateway_id', 'fallback_gateway_id'] as $k) {
            $data[$k] = in_array((int) ($data[$k] ?? 0), $own, true) ? (int) $data[$k] : null;
        }
        $data['reminder_days'] = implode(',', BillReminders::days($data['reminder_days'] ?? '3')) ?: '3';
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }

    protected function afterSaved(Model $record): void
    {
        /** @var SmsSetting $record */
        if ($record->enabled) {
            if ($record->wasChanged('enabled')) {   // switched back on: stopped gateways get another chance
                SmsGateway::where('company_id', $record->company_id)->whereNotNull('failed_at')->update(['failed_at' => null]);
            }
            Engine::kickSms($record->company_id);  // SMS waiting while it was off go now
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gateways')->label(__('গেটওয়ে তালিকা'))->icon(Heroicon::OutlinedSignal)->color('gray')
                ->url(fn () => SmsGatewayResource::getUrl()),
        ];
    }
}
