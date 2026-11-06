<?php

namespace App\Filament\App\Resources\OfficePhones\Pages;

use App\Filament\App\Resources\OfficePhones\OfficePhoneResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOfficePhones extends ManageRecords
{
    protected static string $resource = OfficePhoneResource::class;

    public function getSubheading(): ?string
    {
        return __('ফোন আমাদের সার্ভারে রেজিস্টার হয়, আর আমাদের সার্ভার তার জায়গায় pbx.bd-তে। তাই সব কল রেকর্ড হয় আর কল লগে ওঠে। নম্বর আর রিং গ্রুপ আগের মতোই থাকে।');
    }
}
