<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\VoiceReminderCalls\VoiceReminderCallResource;
use App\Models\Company;
use App\Models\SipSetting;
use App\Models\VoiceFlow;
use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
use App\Support\BillReminders;
use App\Support\Menu;
use App\Support\Sms;
use App\Support\VoiceReminders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** When the voice reminder calls go by themselves (App\Support\VoiceReminders): who, which flow, hours, retries, test mode. */
class VoiceReminderSettings extends CompanySettingsPage
{
    use QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $slug = 'voice-reminder-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'ভয়েস রিমাইন্ডার';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('রিমাইন্ডার সেটিংস');
    }

    public function getTitle(): string|Htmlable
    {
        return __('রিমাইন্ডার কলের সেটিংস');
    }

    public static function canAccess(): bool
    {
        return Menu::can('voice_reminder');
    }

    public function getSubheading(): ?string
    {
        return __('চালু থাকলে হটলাইন থেকে নিজে থেকে কল যায়: মেয়াদ শেষের আগে বিল রিমাইন্ডার, আর বিলের জন্য লাইন বন্ধ হলে। প্রতিটা কলের ঠিক আগে আবার দেখা হয় বিল দেওয়া হয়েছে কি না, লাইন চালু হয়েছে কি না, Left কি না, "আর কল নয়" বলেছেন কি না।');
    }

    protected function record(Company $company): Model
    {
        VoiceFlow::seed($company->id);

        return VoiceReminderSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        /** @var VoiceReminderSetting $record */
        $data = $record->only(['enabled', 'test_mode', 'test_number', 'test_daily_max', 'reminder_on', 'reminder_flow_id', 'reminder_days',
            'due_only', 'line_off_on', 'line_off_flow_id', 'line_off_delay', 'line_off_days', 'window_from', 'window_to', 'skip_jumma',
            'max_calls', 'retry_minutes', 'max_tries', 'minute_rate', 'alt_on', 'alt_max', 'sms_saving']);
        $data['test_number'] = $data['test_number'] ? '0'.substr((string) $data['test_number'], 3) : null;

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        $flows = fn (string $kind) => fn () => VoiceFlow::where('company_id', Filament::getTenant()->getKey())->orderBy('sort')
            ->get()->sortBy(fn (VoiceFlow $f) => $f->kind === $kind ? 0 : 1)->pluck('name', 'id')->all();

        return $schema->components([
            Section::make(__('চালু ও টেস্ট'))->columns(2)->schema([
                Toggle::make('enabled')->label(__('রিমাইন্ডার কল চালু'))->inline(false)
                    ->helperText(fn () => SipSetting::live(Filament::getTenant()->getKey()) ? null : __('হটলাইন (SIP) সেটিং চালু নেই: কল যাবে না')),
                Toggle::make('test_mode')->label(__('টেস্ট মোড'))->inline(false)->live()
                    ->helperText(__('টেস্ট মোডে আসল কাস্টমারকে কল যায় না: তালিকার প্রথম কয়েকজনের তথ্য দিয়ে কল যায় নিচের টেস্ট নম্বরে।')),
                TextInput::make('test_number')->label(__('টেস্ট নম্বর'))->tel()->placeholder('01XXXXXXXXX')
                    ->visible(fn (Get $get) => (bool) $get('test_mode'))->required(fn (Get $get) => (bool) $get('test_mode')),
                TextInput::make('test_daily_max')->label(__('টেস্টে দিনে সর্বোচ্চ কয়টা কল'))->numeric()->minValue(1)->maxValue(20)
                    ->visible(fn (Get $get) => (bool) $get('test_mode')),
            ]),
            Section::make(__('বিল রিমাইন্ডার কল'))->columns(3)->schema([
                Toggle::make('reminder_on')->label(__('চালু'))->inline(false),
                Select::make('reminder_flow_id')->label(__('কোন কল ফ্লো'))->options($flows('reminder'))->required(fn (Get $get) => (bool) $get('reminder_on')),
                TextInput::make('reminder_days')->label(__('মেয়াদের কত দিন আগে'))->required()->maxLength(30)
                    ->placeholder(__('যেমন 2,0'))->helperText(__('কমা দিয়ে কয়েকটা দিন; 0 = মেয়াদের দিনেই'))
                    ->rule('regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'),
                Toggle::make('due_only')->label(__('শুধু বকেয়া থাকলে'))->inline(false),
            ]),
            Section::make(__('লাইন বন্ধের কল'))->columns(3)
                ->description(__('বকেয়া থাকা অবস্থায় লাইন নতুন বন্ধ হলে কল যায়; চালু করার পর থেকে যেগুলো বন্ধ হবে শুধু সেগুলোতে (আগে থেকে বন্ধ লাইনে নয়)।'))
                ->schema([
                    Toggle::make('line_off_on')->label(__('চালু'))->inline(false),
                    Select::make('line_off_flow_id')->label(__('কোন কল ফ্লো'))->options($flows('line_off'))->required(fn (Get $get) => (bool) $get('line_off_on')),
                    TextInput::make('line_off_delay')->label(__('বন্ধ হওয়ার কত মিনিট পরে'))->numeric()->minValue(5)->maxValue(1440)->required(),
                    TextInput::make('line_off_days')->label(__('বন্ধ থাকলে কত দিন চেষ্টা'))->numeric()->minValue(1)->maxValue(7)->required()
                        ->helperText(__('প্রথম দিন + পরের দিনগুলোতে দিনে একবার, যতক্ষণ কাউকে পাওয়া না যায়')),
                ]),
            Section::make(__('কখন ও কতগুলো'))->columns(3)->schema([
                TimePicker::make('window_from')->label(__('কল শুরু'))->seconds(false)->required(),
                TimePicker::make('window_to')->label(__('কল শেষ'))->seconds(false)->required(),
                Toggle::make('skip_jumma')->label(__('শুক্রবার জুমার সময় (১২:৩০-২:৩০) বাদ'))->inline(false),
                TextInput::make('max_calls')->label(__('একসাথে কয়টা কল'))->numeric()->minValue(1)->maxValue(10)->required()
                    ->helperText(__('একটা কল শেষ হলেই পরেরটা যায়। হটলাইনে একসাথে মোট ৪টা লাইন (৩০ অক্টোবরের টেস্ট), আসা কল আর স্টাফের কলও এর মধ্যে; তাই ১-২ রাখা ভালো। হটলাইনে কাস্টমারের কল চললে নতুন রিমাইন্ডার কল অপেক্ষা করে।')),
                TextInput::make('retry_minutes')->label(__('না ধরলে কত মিনিট পরে আবার'))->numeric()->minValue(15)->maxValue(600)->required(),
                TextInput::make('max_tries')->label(__('দিনে সর্বোচ্চ কতবার কল'))->numeric()->minValue(1)->maxValue(5)->required(),
                TextInput::make('minute_rate')->label(__('মিনিট রেট (টাকা)'))->numeric()->minValue(0)->step(0.01)->required(),
            ]),
            Section::make(__('বিকল্প নম্বর ও SMS সাশ্রয়'))->columns(3)->schema([
                Toggle::make('alt_on')->label(__('মূল নম্বরে না পেলে বিকল্প নম্বরে'))->inline(false)
                    ->helperText(__('কাস্টমারের প্রোফাইলের ফোন, টিকেট, WhatsApp, SMS-এর নম্বর থেকে, একটার পর একটা। যে নম্বর দুই বা বেশি অন্য কাস্টমারের সাথেও আছে (স্টাফ, টেকনিশিয়ান, অফিস), সেটা বাদ। কেউ ধরলে বাকিগুলো বাদ।')),
                TextInput::make('alt_max')->label(__('দিনে সর্বোচ্চ কয়টা বিকল্প নম্বর'))->numeric()->minValue(1)->maxValue(3)->required(),
                Toggle::make('sms_saving')->label(__('নিয়মিত কল ধরেন যারা, তাদের বিল রিমাইন্ডার SMS শুধু ব্যাকআপ'))->inline(false)
                    ->helperText(__('শেষ ৩টা কলের ২টা ধরেছেন এমন কাস্টমারকে SMS যায় না, কলে জানানো হয়। কলে কাউকে না পেলে তখনই SMS যায়। টেস্ট মোডে SMS আগের মতোই যায়।')),
            ]),
            Section::make(__('আগামীকাল'))->schema([
                Placeholder::make('preview')->hiddenLabel()->content(function () {
                    $company = (int) Filament::getTenant()->getKey();
                    $s = VoiceReminderSetting::forCompany($company);
                    $p = VoiceReminders::preview($company, $s, now(BillReminders::TZ)->addDay());
                    $days = collect($p['days'])->map(fn ($n, $d) => ($d === 0 ? __('মেয়াদের দিনে') : __(':n দিন আগে', ['n' => $d])).': '.__(':n জন', ['n' => number_format($n)]))->implode(' · ');

                    return __('বিল রিমাইন্ডার: :days (মোট :total জন, আনুমানিক :m মিনিট, প্রায় :c টাকা) · এখন বিলের জন্য বন্ধ লাইন: :off · "আর কল নয়": :o · নিয়মিত কল ধরেন (SMS শুধু ব্যাকআপ): :r', [
                        'days' => $days ?: '—', 'total' => $p['total'], 'm' => $p['minutes'], 'c' => $p['cost'], 'off' => $p['off_now'], 'o' => $p['optouts'], 'r' => $p['regulars'],
                    ]).($s->enabled ? ($s->test_mode ? __(' · এখন টেস্ট মোডে') : '') : __(' · এখন বন্ধ'));
                }),
            ]),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        if ($data['test_mode'] ?? false) {
            $n = Sms::mobile((string) ($data['test_number'] ?? ''));
            if (! $n) {
                throw ValidationException::withMessages(['data.test_number' => __('সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)')]);
            }
            $data['test_number'] = $n;
        } else {
            $data['test_number'] = isset($data['test_number']) ? Sms::mobile((string) $data['test_number']) : $record->test_number;
        }
        $own = VoiceFlow::where('company_id', Filament::getTenant()->getKey())->pluck('id')->all();
        foreach (['reminder_flow_id', 'line_off_flow_id'] as $k) {
            $data[$k] = in_array((int) ($data[$k] ?? 0), $own, true) ? (int) $data[$k] : null;
        }
        $data['reminder_days'] = implode(',', BillReminders::days($data['reminder_days'] ?? '2,0')) ?: '0';
        foreach (['window_from', 'window_to'] as $k) {
            $data[$k] = substr((string) $data[$k], 0, 5);
        }
        if ($data['window_from'] >= $data['window_to']) {
            throw ValidationException::withMessages(['data.window_to' => __('শেষের সময় শুরুর পরে হতে হবে')]);
        }
        $data['company_id'] = Filament::getTenant()->getKey();

        return $data;
    }

    protected function afterSaved(Model $record): void
    {
        /** @var VoiceReminderSetting $record */
        if ($record->wasChanged('enabled') && $record->enabled) {
            // turned (back) on: lines off until now count as already off, never called
            DB::table('voice_line_watch')->where('company_id', $record->company_id)->delete();
        }
        if ($record->wasChanged('test_mode') || ($record->wasChanged('enabled') && ! $record->enabled)) {
            // calls planned under the other mode (or while on) don't go
            VoiceReminderCall::where('company_id', $record->company_id)->where('status', 'planned')
                ->update(['status' => 'expired', 'reason' => __('সেটিং বদলেছে'), 'updated_at' => now()]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calls')->label(__('রিমাইন্ডার কলের তালিকা'))->icon(Heroicon::OutlinedQueueList)->color('gray')
                ->url(fn () => VoiceReminderCallResource::getUrl()),
        ];
    }
}
