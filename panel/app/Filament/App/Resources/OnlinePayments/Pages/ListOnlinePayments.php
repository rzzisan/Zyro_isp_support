<?php

namespace App\Filament\App\Resources\OnlinePayments\Pages;

use App\Filament\App\Resources\OnlinePayments\OnlinePaymentResource;
use App\Filament\App\Widgets\OnlinePaymentStats;
use App\Models\BillingOnlinePayment;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListOnlinePayments extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = OnlinePaymentResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('অনলাইন পেমেন্ট');
    }

    public function getSubheading(): ?string
    {
        $last = BillingOnlinePayment::where('company_id', Filament::getTenant()->getKey())->max('synced_at');

        return __('বিলিং-এর Webhook আর PayBill পেমেন্ট (bKash/Nagad), প্রতি ৩ মিনিটে আসে')
            .($last ? ' · '.__('শেষ আপডেট :time', ['time' => \Illuminate\Support\Carbon::parse($last)->timezone('Asia/Dhaka')->format('d M, g:i A')]) : '')
            .'। '.__('"সেটেল হয়নি" পেমেন্ট বিলিং-এ হাতে সেটেল করতে হয়।');
    }

    protected function getHeaderWidgets(): array
    {
        return [OnlinePaymentStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')->label(__('এখনই Sync'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function () {
                    try {
                        $r = Engine::syncOnlinePayments(Filament::getTenant()->getKey());
                        Notification::make()->success()->title(__('Sync হয়েছে'))
                            ->body(__('Webhook :w, PayBill :p (গত ৩ দিন)', ['w' => $r['webhook'] ?? 0, 'p' => $r['paybill'] ?? 0]))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Sync হয়নি'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
