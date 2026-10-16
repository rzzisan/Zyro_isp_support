<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\ReceiptSetting;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/** Header, QR and footer of the money receipt printed from the বিল কালেকশন page. */
class ReceiptSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function getNavigationLabel(): string
    {
        return __('মানি রিসিট');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('মানি রিসিট');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 3;

    protected function record(Company $company): Model
    {
        return ReceiptSetting::forCompany($company);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('company_name')->label(__('কোম্পানির নাম'))->required()->maxLength(120),
            TextInput::make('address')->label(__('ঠিকানা'))->maxLength(200),
            TextInput::make('email')->label(__('ইমেইল'))->email()->maxLength(120),
            TextInput::make('mobile')->label(__('মোবাইল'))->maxLength(120)->helperText(__('কয়েকটা হলে কমা দিয়ে লিখুন')),
            TextInput::make('qr_link')->label(__('QR লিংক'))->url()->maxLength(255)
                ->helperText(__('যেমন বিকাশ মার্চেন্ট QR-এর লিংক (https://qr.bka.sh/...)। রিসিটের উপরে QR কোড হয়ে ছাপা হবে; খালি রাখলে QR ছাপা হবে না।')),
            TextInput::make('qr_note')->label(__('QR-এর পাশের লেখা'))->maxLength(150),
            Textarea::make('footer')->label(__('নিচের লেখা'))->rows(4)->maxLength(1000)
                ->helperText(__('প্রতিটা লাইন আলাদা লাইনে ছাপা হবে (হেল্পলাইন, বিকাশ নম্বর ইত্যাদি)')),
            Select::make('paper_width')->label(__('প্রিন্টারের কাগজ'))->options([80 => '80 mm', 58 => '58 mm'])->required()->default(80),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }
}
