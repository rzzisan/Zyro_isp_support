<?php

namespace App\Filament\App\Resources\BillCollections\Pages;

use App\Filament\App\BillActions;
use App\Filament\App\Resources\BillCollections\BillCollectionResource;
use Filament\Resources\Pages\ListRecords;

class ListBillCollections extends ListRecords
{
    protected static string $resource = BillCollectionResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('Desk বিল লগ');
    }

    public function getSubheading(): ?string
    {
        return __('Desk থেকে বিলিং-এ নেওয়ার প্রতিটা চেষ্টা (জমা, আটকানো, ব্যর্থ) আর রিসিট। সব বিলের তালিকা: বিল কালেকশন।');
    }

    protected function getHeaderActions(): array
    {
        return [BillActions::receive(),
            \Filament\Actions\Action::make('all')->label(__('বিল কালেকশন'))->color('gray')
                ->url(\App\Filament\App\Resources\DailyBills\DailyBillResource::getUrl('index'))];
    }
}
