<?php

namespace App\Filament\Super\Resources\Companies\Schemas;

use App\Models\Company;
use App\Models\Plan;
use Filament\Forms\Components\CheckboxList;
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
                Section::make(__('কোম্পানি'))->columns(2)->schema([
                    TextInput::make('name')->label(__('কোম্পানির নাম'))->required(),
                    TextInput::make('slug')->label(__('Slug (URL-এ দেখাবে)'))
                        ->helperText(__('খালি রাখলে নাম থেকে নিজে তৈরি হবে'))
                        ->unique(ignoreRecord: true)->alphaDash(),
                    TextInput::make('contact_phone')->label(__('যোগাযোগের ফোন'))->tel(),
                    TextInput::make('contact_email')->label(__('যোগাযোগের ইমেইল'))->email(),
                ]),
                Section::make(__('প্যাকেজ ও মেয়াদ'))->columns(2)->schema([
                    Select::make('plan_id')->label(__('প্যাকেজ'))
                        ->options(fn () => Plan::where('is_active', true)->orderBy('sort')->pluck('name', 'id'))
                        ->searchable()->preload(),
                    Select::make('status')->label(__('অবস্থা'))->required()->default('trial')
                        ->options(['trial' => __('ট্রায়াল'), 'active' => __('চালু'), 'suspended' => __('বন্ধ')]),
                    DateTimePicker::make('trial_ends_at')->label(__('ট্রায়াল শেষ'))
                        ->helperText(__('তৈরির সময় খালি রাখলে প্যাকেজের ট্রায়াল দিন থেকে নিজে বসবে')),
                    DateTimePicker::make('subscription_ends_at')->label(__('সাবস্ক্রিপশন শেষ')),
                ]),
                Section::make(__('বাড়তি ফিচার'))->schema([
                    CheckboxList::make('modules')->label(__('এই কোম্পানির জন্য চালু'))->options(array_map('__', Company::MODULES))
                        ->helperText(__('চালু করলে কোম্পানির প্যানেলে মেনু দেখা যায়; বন্ধ করলে মেনু লুকায়, ডেটা থেকে যায়')),
                ]),
                Section::make(__('Owner অ্যাকাউন্ট'))->description(__('কোম্পানির প্রধান ইউজার; এই ইমেইল দিয়ে /app-এ লগইন করবে। আগে থেকে থাকলে শুধু যুক্ত হবে।'))
                    ->visibleOn('create')->columns(3)->schema([
                        TextInput::make('owner_name')->label(__('নাম')),
                        TextInput::make('owner_email')->label(__('ইমেইল'))->email()->requiredWith('owner_name'),
                        TextInput::make('owner_password')->label(__('পাসওয়ার্ড'))->password()->revealable()->minLength(10)
                            ->helperText(__('নতুন ইউজার হলে লাগবে')),
                    ]),
            ]);
    }
}
