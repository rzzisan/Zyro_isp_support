<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Memberships\MembershipResource;
use App\Models\Company;
use App\Models\Membership;
use App\Services\IspDigitalClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The member's billing-software login, shown read-only: the owner sets every member's login on the team page.
 * Bills and line on/off are made with it (and need it); tickets too, so billing shows who did it.
 */
class MyBillingLogin extends CompanySettingsPage
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    public static function getNavigationLabel(): string
    {
        return __('আমার বিলিং লগইন');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('আমার বিলিং সফটওয়্যার লগইন');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 1;

    /** Everyone in the company can see theirs. */
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
        return [];
    }

    public function getSubheading(): ?string
    {
        return __('প্রতিটি সদস্যের বিলিং লগইন শুধু Owner টিম পেজ থেকে দেন। এই লগইন ছাড়া বিল নেওয়া আর লাইন চালু/বন্ধ করা যায় না। টিকিটও এই লগইনে আপনার নামে হয়।');
    }

    public function form(Schema $schema): Schema
    {
        /** @var Membership $m */
        $m = $this->record(Filament::getTenant());

        return $schema->components([
            Placeholder::make('username')->label(__('বিলিং সফটওয়্যারের ইউজারনেম'))
                ->content($m->hasBillingLogin() ? $m->billing_username : __('দেওয়া নেই — বিল নিতে বা লাইন চালু/বন্ধ করতে পারবেন না। Owner-কে টিম পেজ থেকে দিতে বলুন।')),
            Placeholder::make('status')->label(__('শেষ পরীক্ষা'))
                ->content(fn () => $m->billing_checked_at
                    ? ($m->billing_check_ok ? __('✅ লগইন ঠিক আছে') : __('❌ লগইন হয়নি')).' · '.$m->billing_checked_at->diffForHumans()
                    : __('এখনো পরীক্ষা করা হয়নি')),
        ]);
    }

    /** Read only: no save button. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function save(): void
    {
        abort(403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('লগইন পরীক্ষা করুন'))->icon(Heroicon::OutlinedSignal)
                ->visible(fn () => $this->record(Filament::getTenant())->hasBillingLogin())
                ->action(fn () => $this->testLogin()),
            Action::make('team')->label(__('টিম পেজে লগইন দিন'))->icon(Heroicon::OutlinedUserGroup)->color('gray')
                ->visible(fn () => MembershipResource::isOwner() && MembershipResource::canAccess())
                ->url(fn () => MembershipResource::getUrl('index')),
        ];
    }

    public function testLogin(): void
    {
        /** @var Membership $m */
        $m = $this->record(Filament::getTenant());
        $conn = Filament::getTenant()->billingConnection;
        if (! $m->hasBillingLogin() || ! $conn) {
            Notification::make()->warning()->title($conn ? __('বিলিং লগইন দেওয়া নেই') : __('কোম্পানির বিলিং সংযোগ এখনো সেট করা হয়নি'))->send();

            return;
        }
        try {
            (new IspDigitalClient($conn->base_url, $m->billing_username, $m->billing_password))->login();
            $m->forceFill(['billing_checked_at' => now(), 'billing_check_ok' => true])->save();
            Notification::make()->success()->title(__('লগইন ঠিক আছে'))->send();
        } catch (Throwable $e) {
            $m->forceFill(['billing_checked_at' => now(), 'billing_check_ok' => false])->save();
            Notification::make()->danger()->title(__('লগইন হয়নি'))->body($e->getMessage())->send();
        }
        $this->redirect(static::getUrl());
    }
}
