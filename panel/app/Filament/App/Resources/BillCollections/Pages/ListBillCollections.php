<?php

namespace App\Filament\App\Resources\BillCollections\Pages;

use App\Filament\App\BillActions;
use App\Filament\App\Resources\BillCollections\BillCollectionResource;
use Filament\Resources\Pages\ListRecords;

class ListBillCollections extends ListRecords
{
    protected static string $resource = BillCollectionResource::class;

    protected static ?string $title = 'বিল কালেকশন';

    public function getSubheading(): ?string
    {
        return 'Desk থেকে বিলিং সফটওয়্যারে নেওয়া বিল। approve বিলিং-এর Daily Bill Collection-এ হাতে করতে হয়।';
    }

    protected function getHeaderActions(): array
    {
        return [BillActions::receive()];
    }
}
