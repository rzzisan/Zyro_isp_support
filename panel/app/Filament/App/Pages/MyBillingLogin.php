<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\Membership;
use App\Services\IspDigitalClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Every member's own billing-software login. Tickets a member opens or assigns from the panel are made
 * with it, so the billing software shows who did it. The bot and the sync keep using the company login.
 */
class MyBillingLogin extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'আমার বিলিং লগইন';

    protected static ?string $title = 'আমার বিলিং সফটওয়্যার লগইন';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 1;

    /** Everyone in the company has their own. */
    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company !== null && (bool) auth()->user()?->canAccessTenant($company);
    }

    protected function record(Company $company): Model
    {
        return Membership::where('company_id', $company->id)->where('user_id', auth()->id())->firstOrFail();
    }

    protected function fillData(Model $record): array
    {
        return ['billing_username' => $record->billing_username, 'billing_password' => null];
    }

    public function getSubheading(): ?string
    {
        return 'প্যানেল থেকে আপনি যে টিকিট খুলবেন বা assign করবেন, সেটা বিলিং সফটওয়্যারে আপনার নামেই হবে। বট আর টিকিট sync চলে কোম্পানির (owner-এর দেওয়া) লগইনে।';
    }

    public function form(Schema $schema): Schema
    {
        /** @var Membership $m */
        $m = $this->record(Filament::getTenant());

        return $schema->components([
            TextInput::make('billing_username')->label('বিলিং সফটওয়্যারের ইউজারনেম')->required()
                ->helperText('বিলিং সফটওয়্যারে আপনি যে ইউজারনেমে লগইন করেন'),
            TextInput::make('billing_password')->label('পাসওয়ার্ড')->password()->revealable()
                ->required(! filled($m->billing_password))
                ->helperText(filled($m->billing_password) ? 'এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে, কেউ দেখতে পাবে না'),
            Placeholder::make('status')->label('শেষ পরীক্ষা')
                ->content(fn () => $m->billing_checked_at
                    ? ($m->billing_check_ok ? '✅ লগইন ঠিক আছে' : '❌ লগইন হয়নি').' · '.$m->billing_checked_at->diffForHumans()
                    : 'এখনো পরীক্ষা করা হয়নি'),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        if (blank($data['billing_password'] ?? null)) {
            unset($data['billing_password']);
        }

        return ['billing_username' => trim($data['billing_username'])] + $data;
    }

    protected function afterSaved(Model $record): void
    {
        $this->testLogin();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('লগইন পরীক্ষা করুন')->icon(Heroicon::OutlinedSignal)
                ->action(fn () => $this->testLogin()),
        ];
    }

    public function testLogin(): void
    {
        /** @var Membership $m */
        $m = $this->record(Filament::getTenant());
        $conn = Filament::getTenant()->billingConnection;
        if (! $m->hasBillingLogin() || ! $conn) {
            Notification::make()->warning()->title($conn ? 'আগে ইউজারনেম আর পাসওয়ার্ড সেভ করুন' : 'কোম্পানির বিলিং সংযোগ এখনো সেট করা হয়নি')->send();

            return;
        }
        try {
            (new IspDigitalClient($conn->base_url, $m->billing_username, $m->billing_password))->login();
            $m->forceFill(['billing_checked_at' => now(), 'billing_check_ok' => true])->save();
            Notification::make()->success()->title('লগইন ঠিক আছে')->send();
        } catch (Throwable $e) {
            $m->forceFill(['billing_checked_at' => now(), 'billing_check_ok' => false])->save();
            Notification::make()->danger()->title('লগইন হয়নি')->body($e->getMessage())->send();
        }
        $this->redirect(static::getUrl());
    }
}
