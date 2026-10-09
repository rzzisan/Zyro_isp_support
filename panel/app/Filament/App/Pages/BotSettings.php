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
use Throwable;

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
            'ai_provider' => 'groq', 'voice_provider' => 'gemini', 'bot_mode' => 'shadow', 'auto_ticket' => true,
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
                Select::make('voice_provider')->label('ভয়েস মেসেজ পড়বে')->required()->default('gemini')
                    ->options(['gemini' => 'Google Gemini (আঞ্চলিক বাংলা ও মুখে বলা নম্বর ভালো বোঝে)', 'groq' => 'Groq Whisper'])
                    ->helperText('আগে এই AI-এর সব key একে একে চেষ্টা করবে; সবগুলো ব্যর্থ বা লিমিটে আটকালে অন্য AI-তে যাবে।'),
                Toggle::make('voice_reply')->label('কাস্টমার ভয়েস পাঠালে উত্তর ভয়েসেও দাও')->live()
                    ->helperText('লেখা উত্তর আগের মতোই যাবে, তারপর একই উত্তর ভয়েস মেসেজে (Gemini key লাগবে)। ভয়েস বানানো না গেলে শুধু লেখা যায়।'),
                Select::make('voice_reply_voice')->label('ভয়েস উত্তরের কণ্ঠ')->default('Kore')
                    ->options(['Kore' => 'Kore (নারী)', 'Charon' => 'Charon (পুরুষ)'])
                    ->visible(fn ($get) => (bool) $get('voice_reply')),
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
            Section::make('বটের মূল নির্দেশনা (এডিট করা যায়)')->collapsed()
                ->description('বট কীভাবে কথা বলবে, কী করবে না, টিকিট কখন খুলবে: এই মূল নিয়মগুলো এখানে বদলানো যায়। সাবধানে বদলান: [[TICKET: ...]], [[NOTIFY: ...]], [[ENABLE]], [[DISABLE]] নিয়মগুলো মুছলে বট টিকিট খোলা, কাস্টমারকে জানানো বা লাইন চালু/বন্ধ করা বন্ধ করে দেবে। পুরো লেখা মুছে সেভ করলে ডিফল্ট নির্দেশনায় ফিরে যাবে। অতিরিক্ত নির্দেশনা আর FAQ নিজে থেকেই শেষে যোগ হয়।')
                ->schema([
                    Textarea::make('customer_prompt')->label('কাস্টমার বট')->rows(18)
                        ->helperText('খালি = ডিফল্ট নির্দেশনা'),
                    Textarea::make('technician_prompt')->label('টেকনিশিয়ান বট')->rows(18)
                        ->helperText('{company} = কোম্পানির নাম, {tech} = টেকনিশিয়ানের নাম, {knowledge} = অতিরিক্ত নির্দেশনা + FAQ যেখানে বসবে। খালি = ডিফল্ট।'),
                ]),
            Section::make('বট এখন যা নির্দেশনা পায়')->collapsed()
                ->description('শুধু দেখার জন্য: মূল নির্দেশনা + অতিরিক্ত নির্দেশনা + "বটের FAQ" মিলে বট এখন হুবহু যা পায়। কিছু বদলালে সেভ করে পেজ রিলোড দিন।')
                ->schema([
                    Placeholder::make('customer_prompt_view')->label('কাস্টমারের সাথে কথা বলার সময়')
                        ->content(fn () => $this->promptBox('customer')),
                    Placeholder::make('technician_prompt_view')->label('টেকনিশিয়ানের সাথে কথা বলার সময়')
                        ->content(fn () => $this->promptBox('technician')),
                ]),
        ]);
    }

    private ?array $prompts = null;

    private function prompts(): array
    {
        return $this->prompts ??= Engine::botPrompts(Filament::getTenant()->getKey());
    }

    /** The edit boxes show the built-in rules until the company saves its own version. */
    protected function fillData(Model $record): array
    {
        $data = parent::fillData($record);
        try {
            $data['customer_prompt'] = ($data['customer_prompt'] ?? null) ?: ($this->prompts()['customer_default'] ?? null);
            $data['technician_prompt'] = ($data['technician_prompt'] ?? null) ?: ($this->prompts()['technician_default'] ?? null);
        } catch (Throwable) {
            // engine unreachable: the boxes stay empty, which means "built-in rules"
        }

        return $data;
    }

    private function promptBox(string $which): HtmlString
    {
        try {
            $text = $this->prompts()[$which] ?? '';
        } catch (Throwable $e) {
            $text = 'নির্দেশনা আনা যায়নি: '.$e->getMessage();
        }

        return new HtmlString('<pre style="white-space:pre-wrap;font-size:12px;line-height:1.6;max-height:28rem;overflow:auto;'
            .'padding:12px;border-radius:8px;background:rgba(127,127,127,.08)">'.e($text).'</pre>');
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        // unchanged built-in text is stored as null, so later improvements to the built-in rules still reach this company
        try {
            $defaults = $this->prompts();
        } catch (Throwable) {
            $defaults = [];
        }
        foreach (['customer_prompt' => 'customer_default', 'technician_prompt' => 'technician_default'] as $field => $default) {
            $text = trim((string) ($data[$field] ?? ''));
            $data[$field] = ($text === '' || $text === trim((string) ($defaults[$default] ?? ''))) ? null : $text;
        }
        $this->prompts = null; // the read-only view shows the saved version

        return $data;
    }
}
