<?php

namespace App\Filament\App\Resources\SmsGateways\Pages;

use App\Filament\App\Pages\SmsSettings;
use App\Filament\App\Resources\SmsGateways\SmsGatewayResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageSmsGateways extends ManageRecords
{
    protected static string $resource = SmsGatewayResource::class;

    public function getSubheading(): ?string
    {
        return __('প্রতিটা SMS গেটওয়ে অ্যাকাউন্ট (Khudebarta, Bulk SMS Dhaka)। কোনটা মূল আর কোনটা ব্যাকআপ, সেটা SMS গেটওয়ে সেটিংসে বাছাই করুন।');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->label(__('SMS গেটওয়ে সেটিংস'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
            ->url(fn () => SmsSettings::getUrl())];
    }
}
