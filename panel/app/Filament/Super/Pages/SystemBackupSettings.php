<?php

namespace App\Filament\Super\Pages;

use App\Http\Controllers\SystemBackupController;
use App\Models\SystemBackupSetting;
use App\Models\WaAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * Whole-Desk backup settings: Google Drive sign-in, the recovery key, schedule, retention, what goes in, alerts.
 * The runs themselves are on SystemBackupResource; engine/system_backup.py reads this row every minute.
 */
class SystemBackupSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $slug = 'system-backup-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'সিস্টেম';

    protected static ?int $navigationSort = 2;

    private const NO_AUTOFILL = ['autocomplete' => 'new-password', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true'];

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('ব্যাকআপ সেটিংস');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('সিস্টেম ব্যাকআপ সেটিংস');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $s = SystemBackupSetting::current();
        $this->form->fill([...$s->only(['enabled', 'db_every_hours', 'full_at', 'keep_hourly_days', 'keep_daily_days', 'keep_weekly',
            'keep_monthly', 'keep_deleted_files_days', 'include_files', 'include_secrets', 'include_config', 'bwlimit_mb',
            'weekly_restore_test', 'google_client_id', 'alert_wa_account_id', 'alert_numbers']),
            'google_client_secret' => null, 'key_saved' => (bool) $s->crypt_key_confirmed_at]);
        if ($msg = session('backup_ok')) {
            Notification::make()->success()->title($msg)->send();
        }
        if ($msg = session('backup_error')) {
            Notification::make()->danger()->title($msg)->persistent()->send();
        }
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $s = SystemBackupSetting::current();
        $times = collect(range(0, 47))->mapWithKeys(fn ($i) => [$t = sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30) => $t])->all();

        return $schema->components([
            Section::make(__('১. Google Drive'))
                ->description(__('ব্যাকআপ যে Google অ্যাকাউন্টের Drive-এ যাবে। Desk শুধু নিজের বানানো "ZyroDesk-Backup" ফোল্ডার দেখতে পারে, Drive-এর আর কিছু না।'))
                ->schema([
                    Placeholder::make('drive_status')->label(__('অবস্থা'))->content(fn () => $this->driveStatus($s)),
                    Grid::make(2)->schema([
                        TextInput::make('google_client_id')->label(__('OAuth client ID'))->maxLength(200)
                            ->placeholder('1234-abc.apps.googleusercontent.com')->rule('regex:/^[A-Za-z0-9._-]+$/'),
                        TextInput::make('google_client_secret')->label(__('OAuth client secret'))->password()->revealable()->maxLength(200)
                            ->extraInputAttributes(self::NO_AUTOFILL)
                            ->helperText(filled($s->google_client_secret) ? __('এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন') : null),
                    ]),
                    Placeholder::make('redirect')->label(__('Authorized redirect URI (Google Cloud-এ দিন)'))
                        ->content(SystemBackupController::redirectUri()),
                    Actions::make([
                        Action::make('connect')->label(fn () => $s->connected() ? __('আবার সংযোগ / অ্যাকাউন্ট বদলান') : __('Google Drive সংযোগ করুন'))
                            ->icon(Heroicon::OutlinedLink)->color($s->connected() ? 'gray' : 'primary')
                            ->action(function () {
                                $this->save(quiet: true);

                                return redirect()->route('system-backup.connect');
                            }),
                    ]),
                ]),
            Section::make(__('২. রিকভারি কী'))
                ->description(__('সব ব্যাকআপ এই কী দিয়ে এনক্রিপ্ট হয়। এটা ছাড়া নতুন সার্ভারে কোনো ব্যাকআপ খোলা যাবে না, Google বা আমরা কেউই ফেরত দিতে পারবে না। কাগজে লিখে আর পাসওয়ার্ড ম্যানেজারে রাখুন।'))
                ->schema([
                    Placeholder::make('key')->label(__('কী'))
                        ->content(fn () => $s->crypt_key_confirmed_at
                            ? __('লুকানো (আঙুলের ছাপ :fp) · :when সংরক্ষিত বলে নিশ্চিত করেছেন', ['fp' => $s->fingerprint(),
                                'when' => $s->crypt_key_confirmed_at->timezone('Asia/Dhaka')->format('d M Y')])
                            : new HtmlString('<code style="font-size:1.25rem;letter-spacing:.05em">'.e($s->crypt_key).'</code>')),
                    Placeholder::make('sheet')->hiddenLabel()->content(new HtmlString('<a style="color:var(--primary-600, #0f7c7b); text-decoration:underline; font-weight:600" href="'
                        .route('system-backup.recovery').'">'.e(__('রিকভারি শিট ডাউনলোড (.txt)')).'</a> · '
                        .e(__('কী, Drive ফোল্ডার আর OAuth তথ্য এক ফাইলে; নিরাপদ জায়গায় রাখুন, কারো সাথে শেয়ার করবেন না।')))),
                    Toggle::make('key_saved')->label(__('আমি কী লিখে নিরাপদে রেখেছি'))
                        ->helperText(__('টিক না দেওয়া পর্যন্ত অটো ব্যাকআপ চালু হবে না')),
                ]),
            Section::make(__('৩. সময়সূচি'))->columns(4)->schema([
                Toggle::make('enabled')->label(__('অটো ব্যাকআপ চালু'))->columnSpanFull()
                    ->helperText(__('চালু থাকলে প্রতি রাতে পুরো ব্যাকআপ আর মাঝে মাঝে শুধু ডাটাবেস')),
                Select::make('full_at')->label(__('পুরো ব্যাকআপের সময় (ঢাকা)'))->options($times)->required()->selectablePlaceholder(false),
                Select::make('db_every_hours')->label(__('শুধু ডাটাবেস, প্রতি'))->required()->selectablePlaceholder(false)
                    ->options([0 => __('বন্ধ'), 2 => __('২ ঘণ্টা'), 3 => __('৩ ঘণ্টা'), 4 => __('৪ ঘণ্টা'), 6 => __('৬ ঘণ্টা'), 8 => __('৮ ঘণ্টা'), 12 => __('১২ ঘণ্টা')]),
                TextInput::make('bwlimit_mb')->label(__('আপলোড গতির সীমা'))->integer()->minValue(0)->maxValue(100)->required()
                    ->suffix('MB/s')->helperText(__('০ = সীমা নেই')),
                Toggle::make('weekly_restore_test')->label(__('প্রতি রবিবার অটো রিস্টোর টেস্ট')),
            ]),
            Section::make(__('৪. কতদিন রাখবে'))->columns(5)->schema([
                TextInput::make('keep_hourly_days')->label(__('ঘণ্টার কপি'))->integer()->minValue(1)->maxValue(60)->required()->suffix(__('দিন')),
                TextInput::make('keep_daily_days')->label(__('দৈনিক কপি'))->integer()->minValue(1)->maxValue(365)->required()->suffix(__('দিন')),
                TextInput::make('keep_weekly')->label(__('সাপ্তাহিক (রবিবার)'))->integer()->minValue(0)->maxValue(104)->required()->suffix(__('সপ্তাহ')),
                TextInput::make('keep_monthly')->label(__('মাসিক (১ তারিখ)'))->integer()->minValue(0)->maxValue(120)->required()->suffix(__('মাস')),
                TextInput::make('keep_deleted_files_days')->label(__('মুছে যাওয়া ফাইল'))->integer()->minValue(1)->maxValue(365)->required()->suffix(__('দিন')),
            ]),
            Section::make(__('৫. কী কী নেবে'))->columns(3)
                ->description(__('ডাটাবেস সবসময় যায়। কোড GitHub-এ আছে, তবু প্রতিবার চলমান commit আর লাইভের অ-commit বদল যায়, সপ্তাহে একবার পুরো git bundle।'))
                ->schema([
                    Toggle::make('include_files')->label(__('ফাইল: মিডিয়া, কল রেকর্ডিং, MikroTik ব্যাকআপ, APK')),
                    Toggle::make('include_secrets')->label(__('গোপন ফাইল: .env, Android keystore, Firebase key')),
                    Toggle::make('include_config')->label(__('সার্ভার কনফিগ: nginx, systemd, SSL, Asterisk')),
                ]),
            Section::make(__('৬. অ্যালার্ট (WhatsApp)'))->columns(2)
                ->description(__('ব্যাকআপ বা রিস্টোর টেস্ট ফেল করলে, ২৬ ঘণ্টা কোনো সফল ব্যাকআপ না হলে, বা Google সংযোগ কেটে গেলে এই নম্বরে মেসেজ যায়। গত ২৪ ঘণ্টায় ওই নম্বর থেকে মেসেজ না এলে অনুমোদিত টেমপ্লেট "system_backup_alert" দিয়ে যায় (প্রথমবার নিজে Meta-তে জমা হয়)।'))
                ->schema([
                    Select::make('alert_wa_account_id')->label(__('কোন WhatsApp নম্বর থেকে'))->placeholder(__('বন্ধ'))
                        ->options(fn () => WaAccount::with('company')->get()->mapWithKeys(fn (WaAccount $a) => [$a->id => trim(($a->display_phone_number ?: $a->phone_number_id)
                            .' · '.($a->company?->name ?? ''))])->all()),
                    Textarea::make('alert_numbers')->label(__('কাকে পাঠাবে'))->rows(2)->placeholder('01712345678')
                        ->helperText(__('কমা বা নতুন লাইনে মোবাইল নম্বর')),
                ]),
        ]);
    }

    private function driveStatus(SystemBackupSetting $s): HtmlString|string
    {
        if (! $s->connected()) {
            return __('সংযোগ নেই');
        }
        $parts = ['✅ '.e($s->google_email).' · '.__('সংযুক্ত :when', ['when' => $s->google_connected_at?->diffForHumans()])];
        if ($s->drive_total) {
            $parts[] = __(':used ব্যবহার, :free খালি', ['used' => Number::fileSize((int) $s->drive_used, 1),
                'free' => Number::fileSize(max(0, (int) $s->drive_total - (int) $s->drive_used), 1)]);
        }
        if ($s->google_error) {
            $parts[] = '<span style="color:#dc2626">❌ '.e($s->google_error).'</span>';
        }

        return new HtmlString(implode(' · ', $parts));
    }

    public function save(bool $quiet = false): void
    {
        abort_unless(static::canAccess(), 403);
        $s = SystemBackupSetting::current();
        $data = $this->form->getState();
        if (blank($data['google_client_secret'] ?? null)) {
            unset($data['google_client_secret']);
        }
        $data['google_client_id'] = filled($data['google_client_id'] ?? null) ? trim($data['google_client_id']) : null;
        $data['alert_numbers'] = collect(preg_split('/[\s,]+/', (string) ($data['alert_numbers'] ?? '')))
            ->map(fn ($n) => \App\Support\Sms::mobile($n))->filter()->unique()->implode("\n") ?: null;
        $saved = (bool) ($data['key_saved'] ?? false);
        unset($data['key_saved']);
        $data['crypt_key_confirmed_at'] = $saved ? ($s->crypt_key_confirmed_at ?? now()) : null;
        if (($data['enabled'] ?? false) && (! $saved || ! $s->connected())) {
            $data['enabled'] = false;
            if (! $quiet) {
                Notification::make()->warning()->title(__('অটো ব্যাকআপ চালু হয়নি'))
                    ->body(__('আগে Google Drive সংযোগ দিন আর রিকভারি কী লিখে রাখার ঘরে টিক দিন'))->send();
            }
        }
        $s->fill($data)->save();
        $this->data['enabled'] = $s->enabled;
        if (! $quiet) {
            Notification::make()->success()->title(__('সেভ হয়েছে'))->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label(__('সেভ করুন'))->submit('save')]),
                ]),
        ]);
    }
}
