<?php

namespace App\Filament\App\Resources\DailyBills\Pages;

use App\Filament\App\BillActions;
use App\Filament\App\Resources\BillCollections\BillCollectionResource;
use App\Filament\App\Resources\DailyBills\DailyBillResource;
use App\Filament\App\Widgets\DailyBillStats;
use App\Models\BillingDailyBill;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListDailyBills extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = DailyBillResource::class;

    protected static ?string $title = 'বিল কালেকশন';

    public function getSubheading(): ?string
    {
        $last = BillingDailyBill::where('company_id', Filament::getTenant()->getKey())->max('synced_at');

        return 'বিলিং সফটওয়্যারের Daily Bill Collection-এর সব বিল, প্রতি ১০ মিনিটে আপডেট হয়'
            .($last ? ' · শেষ আপডেট '.\Illuminate\Support\Carbon::parse($last)->timezone('Asia/Dhaka')->format('d M, g:i A') : '')
            .'। approve বিলিং-এ হাতে করতে হয়।';
    }

    protected function getHeaderWidgets(): array
    {
        return [DailyBillStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            BillActions::receive(),
            Action::make('syncNow')->label('এখনই Sync')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function () {
                    try {
                        $r = Engine::syncDailyBills(Filament::getTenant()->getKey());
                        Notification::make()->success()->title('Sync হয়েছে')->body(($r['rows'] ?? 0).'টা বিল (গত ৩ দিন)')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Sync হয়নি')->body($e->getMessage())->send();
                    }
                }),
            Action::make('deskLog')->label('Desk বিল লগ')->icon(Heroicon::OutlinedClipboardDocumentList)->color('gray')
                ->url(BillCollectionResource::getUrl('index')),
        ];
    }
}
