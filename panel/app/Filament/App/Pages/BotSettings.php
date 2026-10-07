<?php

namespace App\Filament\App\Pages;

use App\Models\AiKey;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class BotSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'বট সেটিংস';

    protected static ?string $title = 'বট সেটিংস';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return parent::canAccess() && \App\Support\Menu::can('bot');
    }

    protected function record(Company $company): Model
    {
        return $company->botSetting()->firstOrNew([], [
            'ai_provider' => 'groq', 'bot_mode' => 'shadow', 'auto_ticket' => true,
            'reply_signature' => '- '.$company->name,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('AI')->columns(2)->schema([
                Select::make('ai_provider')->label('ডিফল্ট AI প্রোভাইডার')->options(AiKey::providerOptions())->required()->live(),
                TextInput::make('ai_model')->label('মডেল')
                    ->placeholder(fn ($get) => AiKey::PROVIDERS[$get('ai_provider')][2] ?? '')
                    ->helperText('খালি রাখলে প্রোভাইডারের সাজেস্টেড মডেল; key-গুলো "AI key" পেজে যোগ করুন'),
            ]),
            Section::make('বট কীভাবে কাজ করবে')->schema([
                Select::make('bot_mode')->label('বট মোড')->required()->options([
                    'shadow' => 'চুপ মোড — শুধু খসড়া, কাস্টমারকে কিছু পাঠাবে না',
                    'live' => 'চালু — কাস্টমারকে নিজে উত্তর দেবে',
                    'off' => 'বন্ধ',
                ]),
                Textarea::make('live_allowlist')->label('চালু মোডে শুধু এই নম্বরগুলোকে উত্তর দেবে')
                    ->helperText('কমা দিয়ে আলাদা; খালি রাখলে সবাইকে')->rows(2),
                Toggle::make('auto_ticket')->label('দরকার হলে বিলিং সফটওয়্যারে নিজে টিকেট খুলবে (SMS ছাড়া)'),
                TextInput::make('reply_signature')->label('প্রতিটা উত্তরের শেষে স্বাক্ষর'),
                Textarea::make('extra_prompt')->label('অতিরিক্ত নির্দেশনা')
                    ->helperText('পেমেন্টের নিয়ম (বিকাশ/নগদ নম্বর), অফিসের সময়, বিশেষ নোটিশ — বট এগুলো হুবহু মানবে')
                    ->rows(8),
            ]),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }
}
