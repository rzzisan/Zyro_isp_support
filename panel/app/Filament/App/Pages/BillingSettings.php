<?php

namespace App\Filament\App\Pages;

use App\Models\BillingConnection;
use App\Models\Company;
use App\Services\IspDigitalClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class BillingSettings extends CompanySettingsPage
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function getNavigationLabel(): string
    {
        return __('বিলিং সংযোগ');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('বিলিং সফটওয়্যার সংযোগ');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return parent::canAccess() && \App\Support\Menu::can('billing');
    }

    protected function record(Company $company): Model
    {
        return $company->billingConnection()->firstOrNew([], ['provider' => 'ispdigital', 'base_url' => 'https://']);
    }

    protected function fillData(Model $record): array
    {
        return ['provider' => $record->provider, 'base_url' => $record->base_url,
            'username' => $record->username, 'password' => null,
            'live_from_billing' => $record->live_from_billing ?? true, 'onu_from_billing' => $record->onu_from_billing ?? true,
            'tickets_in_billing' => $record->tickets_in_billing ?? true];
    }

    public function form(Schema $schema): Schema
    {
        $record = $this->record(Filament::getTenant());

        return $schema->components([
            Select::make('provider')->label(__('বিলিং সফটওয়্যার'))->options(['ispdigital' => 'ISP Digital (SoftifyBD)'])
                ->required()->default('ispdigital'),
            TextInput::make('base_url')->label(__('প্যানেলের ঠিকানা'))->url()->required()
                ->placeholder('https://yourisp.ispdigital.cloud'),
            TextInput::make('username')->label(__('ইউজারনেম'))->required()
                ->helperText(__('বটের জন্য আলাদা স্টাফ ইউজার বানিয়ে দিন — টিকেট খোলার অনুমতিসহ')),
            TextInput::make('password')->label(__('পাসওয়ার্ড'))->password()->revealable()
                ->required(! $record->exists)
                ->helperText($record->exists ? __('এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে')),
            Placeholder::make('status')->label(__('শেষ পরীক্ষা'))
                ->content(fn () => $record->last_checked_at
                    ? ($record->last_check_ok ? '✅ ' : '❌ ').$record->last_check_message.' · '.$record->last_checked_at->diffForHumans()
                    : __('এখনো পরীক্ষা করা হয়নি')),
            Section::make(__('বিলিং থেকে কী কী আনা হবে'))
                ->description(__('সংযোগ, MAC আর ONU সবসময় আগে আমাদের MikroTik ও OLT থেকে আসে। সুইচ চালু থাকলে যেটা আমাদের কাছে নেই শুধু সেটা বিলিং থেকে আনা হয়; বন্ধ থাকলে বিলিং-এ যাওয়াই হয় না।'))
                ->schema([
                    Toggle::make('live_from_billing')->label(__('বিলিং থেকে লাইভ স্ট্যাটাস / MAC'))->default(true)
                        ->helperText(fn () => __('কোনো MikroTik উত্তর না দিলে, বা কাস্টমারের MAC আমরা কখনো না দেখলে।').' '.$this->fallbackText('live')),
                    Toggle::make('onu_from_billing')->label(__('বিলিং থেকে ONU পাওয়ার'))->default(true)
                        ->helperText(fn () => __('কাস্টমারের MAC আমাদের কোনো OLT-এ না মিললে।').' '.$this->fallbackText('onu')),
                ]),
            Section::make(__('টিকিট'))
                ->schema([
                    Toggle::make('tickets_in_billing')->label(__('নতুন টিকিট বিলিং সফটওয়্যারে খোলা হবে'))->default(true)
                        ->helperText(fn () => __('বন্ধ করলে নতুন টিকিট (ডেস্ক, WhatsApp বট, ফোন বট) Desk-এই খোলা, অ্যাসাইন আর সমাধান হবে, বিলিং-এ যাবে না। আগের বিলিং টিকিট বিলিং-এই সমাধান হবে। যেকোনো সময় আবার চালু করা যায়।').' '.$this->ticketText()),
                ]),
        ]);
    }

    /** How often billing had to fill in over the last 7 days, so the owner sees when the switch can go off. */
    public function fallbackText(string $kind): string
    {
        $rows = DB::table('billing_fallbacks')->where('company_id', Filament::getTenant()->getKey())->where('kind', $kind)
            ->where('day', '>=', now('Asia/Dhaka')->subDays(6)->toDateString())
            ->selectRaw('reason, sum(count) AS n')->groupBy('reason')->pluck('n', 'reason');
        if ($rows->isEmpty()) {
            return __('গত ৭ দিনে বিলিং লাগেনি।');
        }
        $labels = ['no_router' => __('MikroTik উত্তর দেয়নি'), 'no_mac' => __('MAC জানা ছিল না'), 'not_on_own_olt' => __('OLT-এ ONU মেলেনি')];

        return __('গত ৭ দিনে বিলিং লেগেছে: ').$rows->map(fn ($n, $r) => ($labels[$r] ?? $r).' '.$n.' '.__('বার'))->implode(', ');
    }

    /** How many tickets live in the Desk now, and which number the next one gets. */
    public function ticketText(): string
    {
        $companyId = Filament::getTenant()->getKey();
        $open = DB::table('billing_tickets')->where('company_id', $companyId)->where('source', 'desk')
            ->whereIn('state', ['pending', 'processing'])->count();
        $last = DB::table('ticket_numbers')->where('company_id', $companyId)->value('last_no');

        return $last ? __('Desk-এ খোলা টিকিট :open টা, শেষ নম্বর :last।', ['open' => $open, 'last' => $last])
            : __('Desk টিকিটের নম্বর বিলিংয়ের নম্বরের পরের এক লাখের ঘর থেকে শুরু হবে (যেমন 100001), তাই কখনো মিলে যাবে না।');
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $data['base_url'] = rtrim($data['base_url'], '/');
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('সংযোগ পরীক্ষা করুন'))->icon(Heroicon::OutlinedSignal)
                ->action(fn () => $this->testConnection()),
        ];
    }

    public function testConnection(): void
    {
        $record = $this->record(Filament::getTenant());
        if (! $record->exists) {
            Notification::make()->warning()->title(__('আগে তথ্যগুলো সেভ করুন'))->send();

            return;
        }
        try {
            $client = new IspDigitalClient($record->base_url, $record->username, $record->password);
            $client->login();
            $count = $client->customerCount();
            $record->forceFill(['last_checked_at' => now(), 'last_check_ok' => true,
                'last_check_message' => "সংযোগ ঠিক আছে — {$count} জন কাস্টমার পাওয়া গেছে"])->save();
            Notification::make()->success()->title(__('সংযোগ ঠিক আছে — :n জন কাস্টমার', ['n' => $count]))->send();
        } catch (Throwable $e) {
            $record->forceFill(['last_checked_at' => now(), 'last_check_ok' => false,
                'last_check_message' => mb_substr($e->getMessage(), 0, 300)])->save();
            Notification::make()->danger()->title(__('সংযোগ হয়নি'))->body($e->getMessage())->send();
        }
        $this->redirect(static::getUrl());
    }
}
