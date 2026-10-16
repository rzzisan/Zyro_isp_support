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
use Livewire\Attributes\On;
use RuntimeException;

class ListDailyBills extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = DailyBillResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('বিল কালেকশন');
    }

    public function getSubheading(): ?string
    {
        $last = BillingDailyBill::where('company_id', Filament::getTenant()->getKey())->max('synced_at');

        return __('বিলিং সফটওয়্যারের Daily Bill Collection-এর সব বিল। এই পেজ খোলা থাকলে নতুন বিল ৫ সেকেন্ডে আসে')
            .($last ? ' · '.__('শেষ আপডেট :time', ['time' => \Illuminate\Support\Carbon::parse($last)->timezone('Asia/Dhaka')->format('d M, g:i A')]) : '')
            .'। '.__('approve বিলিং-এ হাতে করতে হয়।');
    }

    /** New/changed bills seen by the live check (footer script): re-render the table with fresh rows. */
    #[On('daily-bills-changed')]
    public function refreshFromLive(): void {}

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        return view('filament.app.daily-bills.live', ['url' => route('daily-bills.live', ['company' => Filament::getTenant()->slug])]);
    }

    protected function getHeaderWidgets(): array
    {
        return [DailyBillStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            BillActions::receive(),
            Action::make('syncNow')->label(__('এখনই Sync'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function () {
                    try {
                        $r = Engine::syncDailyBills(Filament::getTenant()->getKey());
                        Notification::make()->success()->title(__('Sync হয়েছে'))->body(__(':nটা বিল (গত ৩ দিন)', ['n' => $r['rows'] ?? 0]))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Sync হয়নি'))->body($e->getMessage())->send();
                    }
                }),
            Action::make('deskLog')->label(__('Desk বিল লগ'))->icon(Heroicon::OutlinedClipboardDocumentList)->color('gray')
                ->url(BillCollectionResource::getUrl('index')),
        ];
    }
}
