<?php

namespace App\Filament\Super\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('নাম')->required(),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')->label('পাসওয়ার্ড')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))->minLength(10)
                    ->helperText('এডিটের সময় খালি রাখলে পাসওয়ার্ড বদলাবে না'),
                Toggle::make('is_super_admin')->label('সুপার অ্যাডমিন'),
            ]);
    }
}
