<?php

namespace App\Filament\App\Resources\SmsCampaigns\Pages;

use App\Filament\App\Pages\SmsSettings;
use App\Filament\App\Resources\SmsCampaigns\SmsCampaignResource;
use App\Filament\App\SmsActions;
use App\Filament\App\Widgets\SmsStats;
use App\Models\SmsSetting;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListSmsCampaigns extends ListRecords
{
    protected static string $resource = SmsCampaignResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('SMS পাঠান');
    }

    public function getSubheading(): ?string
    {
        return __('কাস্টমার বাছাই হয় Desk-এর নিজের তালিকা থেকে; SMS যায় কোম্পানির SMS গেটওয়ে দিয়ে, পেছনে ছোট ছোট ভাগে। পেজ বন্ধ করলেও পাঠানো চলতে থাকে।');
    }

    protected function getHeaderWidgets(): array
    {
        return [SmsStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            SmsActions::send(),
            Action::make('balance')->label(__('ব্যালান্স রিফ্রেশ'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => SmsSetting::forCompany(Filament::getTenant())->configured())
                ->action(function () {
                    try {
                        $r = Engine::smsBalance(Filament::getTenant()->getKey());
                        Notification::make()->success()->title(__('ব্যালান্স: :amount', ['amount' => $r['balance'] ?? '—']))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('ব্যালান্স পাওয়া যায়নি'))->body($e->getMessage())->send();
                    }
                    $this->dispatch('sms-balance-updated');
                }),
            Action::make('settings')->label(__('গেটওয়ে সেটিংস'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->visible(fn () => SmsSettings::canAccess())
                ->url(fn () => SmsSettings::getUrl()),
        ];
    }
}
