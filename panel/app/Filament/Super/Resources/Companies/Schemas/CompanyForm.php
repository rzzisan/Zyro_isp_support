<?php

namespace App\Filament\Super\Resources\Companies\Schemas;

use App\Models\Plan;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('কোম্পানি')->columns(2)->schema([
                    TextInput::make('name')->label('কোম্পানির নাম')->required(),
                    TextInput::make('slug')->label('Slug (URL-এ দেখাবে)')
                        ->helperText('খালি রাখলে নাম থেকে নিজে তৈরি হবে')
                        ->unique(ignoreRecord: true)->alphaDash(),
                    TextInput::make('contact_phone')->label('যোগাযোগের ফোন')->tel(),
                    TextInput::make('contact_email')->label('যোগাযোগের ইমেইল')->email(),
                ]),
                Section::make('প্যাকেজ ও মেয়াদ')->columns(2)->schema([
                    Select::make('plan_id')->label('প্যাকেজ')
                        ->options(fn () => Plan::where('is_active', true)->orderBy('sort')->pluck('name', 'id'))
                        ->searchable()->preload(),
                    Select::make('status')->label('অবস্থা')->required()->default('trial')
                        ->options(['trial' => 'ট্রায়াল', 'active' => 'চালু', 'suspended' => 'বন্ধ']),
                    DateTimePicker::make('trial_ends_at')->label('ট্রায়াল শেষ')
                        ->helperText('তৈরির সময় খালি রাখলে প্যাকেজের ট্রায়াল দিন থেকে নিজে বসবে'),
                    DateTimePicker::make('subscription_ends_at')->label('সাবস্ক্রিপশন শেষ'),
                ]),
                Section::make('Owner অ্যাকাউন্ট')->description('কোম্পানির প্রধান ইউজার; এই ইমেইল দিয়ে /app-এ লগইন করবে। আগে থেকে থাকলে শুধু যুক্ত হবে।')
                    ->visibleOn('create')->columns(3)->schema([
                        TextInput::make('owner_name')->label('নাম'),
                        TextInput::make('owner_email')->label('ইমেইল')->email()->requiredWith('owner_name'),
                        TextInput::make('owner_password')->label('পাসওয়ার্ড')->password()->revealable()->minLength(10)
                            ->helperText('নতুন ইউজার হলে লাগবে'),
                    ]),
            ]);
    }
}
