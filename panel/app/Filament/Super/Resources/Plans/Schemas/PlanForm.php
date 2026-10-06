<?php

namespace App\Filament\Super\Resources\Plans\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('প্যাকেজের নাম')->required(),
                TextInput::make('price_monthly')->label('মাসিক দাম (টাকা)')->numeric()->minValue(0)->required()->default(0),
                TextInput::make('max_agents')->label('সর্বোচ্চ ইউজার/এজেন্ট')->numeric()->minValue(1)->required()->default(3),
                TextInput::make('max_whatsapp_numbers')->label('সর্বোচ্চ WhatsApp নম্বর')->numeric()->minValue(1)->required()->default(1),
                TextInput::make('max_bot_replies')->label('মাসে সর্বোচ্চ বট উত্তর')->numeric()->minValue(1)
                    ->helperText('খালি রাখলে সীমাহীন'),
                TextInput::make('trial_days')->label('ফ্রি ট্রায়াল (দিন)')->numeric()->minValue(0)->required()->default(0),
                TextInput::make('sort')->label('ক্রম')->numeric()->default(0),
                Toggle::make('is_active')->label('চালু (নতুন কোম্পানিকে দেওয়া যাবে)')->default(true),
                Textarea::make('description')->label('বিবরণ')->columnSpanFull(),
            ]);
    }
}
