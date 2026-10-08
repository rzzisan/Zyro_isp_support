<?php

namespace App\Filament\App\Pages;

use App\Models\AiKey;
use App\Models\Company;
use App\Services\Engine;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use RuntimeException;

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
            Section::make('বট এখন যা নির্দেশনা পায়')->collapsed()
                ->description('শুধু দেখার জন্য: বটের ভেতরের নিয়ম + উপরের অতিরিক্ত নির্দেশনা + "বটের FAQ"। অতিরিক্ত নির্দেশনা বদলালে সেভ করে পেজ রিলোড দিলে এখানে দেখা যাবে।')
                ->schema([
                    Placeholder::make('customer_prompt')->label('কাস্টমারের সাথে কথা বলার সময়')
                        ->content(fn () => $this->promptBox('customer')),
                    Placeholder::make('technician_prompt')->label('টেকনিশিয়ানের সাথে কথা বলার সময়')
                        ->content(fn () => $this->promptBox('technician')),
                ]),
        ]);
    }

    private ?array $prompts = null;

    private function promptBox(string $which): HtmlString
    {
        try {
            $this->prompts ??= Engine::botPrompts(Filament::getTenant()->getKey());
            $text = $this->prompts[$which] ?? '';
        } catch (RuntimeException $e) {
            $text = 'নির্দেশনা আনা যায়নি: '.$e->getMessage();
        }

        return new HtmlString('<pre style="white-space:pre-wrap;font-size:12px;line-height:1.6;max-height:28rem;overflow:auto;'
            .'padding:12px;border-radius:8px;background:rgba(127,127,127,.08)">'.e($text).'</pre>');
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }
}
