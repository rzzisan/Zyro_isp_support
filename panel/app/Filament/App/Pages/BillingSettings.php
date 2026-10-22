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
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
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
            'username' => $record->username, 'password' => null];
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
        ]);
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
