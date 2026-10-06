<?php

namespace App\Filament\Super\Resources\Companies\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('কোম্পানির নাম')->required(),
                TextInput::make('slug')->label('Slug (URL-এ দেখাবে)')
                    ->helperText('খালি রাখলে নাম থেকে নিজে তৈরি হবে')
                    ->unique(ignoreRecord: true)->alphaDash(),
                Select::make('status')->label('অবস্থা')->required()->default('trial')
                    ->options(['trial' => 'ট্রায়াল', 'active' => 'চালু', 'suspended' => 'বন্ধ']),
                TextInput::make('plan')->label('প্যাকেজ'),
                DateTimePicker::make('trial_ends_at')->label('ট্রায়াল শেষ'),
                TextInput::make('contact_phone')->label('যোগাযোগের ফোন')->tel(),
                TextInput::make('contact_email')->label('যোগাযোগের ইমেইল')->email(),
            ]);
    }
}
