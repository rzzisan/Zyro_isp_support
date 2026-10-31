<?php

namespace App\Filament\App\Resources\VoiceTemplates;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\VoiceTemplates\Pages\ManageVoiceTemplates;
use App\Models\BillingCustomer;
use App\Models\VoiceTemplate;
use App\Support\Menu;
use App\Support\VoiceTemplates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Voice reminder messages (like the SMS templates): text with placeholders read by Gemini TTS in a chosen voice, or
 * an uploaded recording. "শুনুন" makes the audio with a real customer's data (or an example) and plays it here.
 */
class VoiceTemplateResource extends Resource
{
    use QuickAccessible;

    protected static ?string $model = VoiceTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSpeakerWave;

    protected static ?string $slug = 'voice-templates';

    protected static string|\UnitEnum|null $navigationGroup = 'ভয়েস রিমাইন্ডার';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('ভয়েস টেমপ্লেট');
    }

    public static function getModelLabel(): string
    {
        return __('ভয়েস টেমপ্লেট');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ভয়েস টেমপ্লেট');
    }

    public static function canAccess(): bool
    {
        return Menu::can('voice_reminder');
    }

    private static function company(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }

    private static function slug(): string
    {
        return (string) Filament::getTenant()?->slug;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('নাম'))->required()->maxLength(100),
            Select::make('type')->label(__('ধরন'))->options(array_map('__', VoiceTemplate::TYPES))->default('general')->required()
                ->selectablePlaceholder(false)->live()
                ->helperText(fn (Get $get) => match ($get('type')) {
                    'reminder' => __('বিল রিমাইন্ডার কলের শুরুতে বাজে'),
                    'line_off' => __('বিলের জন্য লাইন বন্ধ হলে যে কল যায় তার শুরুতে বাজে'),
                    'menu' => __('কোন বাটনে কী হয় তা বলে (বাটনের কাজ কল ফ্লো-তে ঠিক হয়)'),
                    'result' => __('বাটন চাপার পর যা শোনায় (SMS গেছে, লাইন চালু হয়েছে ...)'),
                    'hold' => __('কাস্টমার স্টাফের জন্য অপেক্ষা করার সময় শোনেন'),
                    'whisper' => __('স্টাফ কল ধরার আগে শুধু তিনি শোনেন: কাস্টমারের তথ্য'),
                    default => __('যেকোনো কল ফ্লো-তে বসানো যায়'),
                }),
            ToggleButtons::make('source')->label(__('অডিও'))->inline()->required()->default('tts')->live()
                ->options(['tts' => __('লেখা থেকে কণ্ঠ (AI)'), 'upload' => __('নিজের রেকর্ড আপলোড')])
                ->columnSpanFull(),
            View::make('filament.app.sms.placeholders')->columnSpanFull()
                ->visible(fn (Get $get) => $get('source') !== 'upload')
                ->viewData(fn (Get $get) => ['items' => VoiceTemplates::placeholders((string) ($get('type') ?: 'general'))]),
            Textarea::make('body')->label(__('লেখা'))->rows(4)->maxLength(1500)->columnSpanFull()
                ->required(fn (Get $get) => $get('source') !== 'upload')
                ->visible(fn (Get $get) => $get('source') !== 'upload')
                ->extraInputAttributes(['data-sms-body' => 'true'])->live(debounce: 500)
                ->helperText(function (Get $get) {
                    $bad = VoiceTemplates::unknown((string) $get('body'));

                    return $bad ? __('চেনা যায়নি: :list (বানান দেখুন)', ['list' => '{'.implode('}, {', $bad).'}'])
                        : __('সংখ্যা ও তারিখ বাংলায় পড়বে, যেমন "৫২০ টাকা, ৩১ অক্টোবর"। ছোট বাক্যে লিখলে পরিষ্কার শোনায়।');
                }),
            Select::make('voice')->label(__('কণ্ঠ'))->options(array_map('__', VoiceTemplate::VOICES))->default('Kore')
                ->selectablePlaceholder(false)->visible(fn (Get $get) => $get('source') !== 'upload'),
            Select::make('speed')->label(__('গতি'))->options(array_map('__', VoiceTemplate::SPEEDS))->default('1.00')
                ->selectablePlaceholder(false)->visible(fn (Get $get) => $get('source') !== 'upload')
                ->formatStateUsing(fn ($state) => number_format((float) ($state ?: 1), 2, '.', '')),
            FileUpload::make('audio_path')->label(__('রেকর্ড (MP3/WAV/M4A/OGG, ৫ MB পর্যন্ত)'))->columnSpanFull()
                ->disk('local')->directory(fn () => 'voice-templates/'.static::company())->visibility('private')
                ->acceptedFileTypes(['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/webm'])
                ->maxSize(5120)
                ->required(fn (Get $get) => $get('source') === 'upload')
                ->visible(fn (Get $get) => $get('source') === 'upload')
                ->helperText(__('প্লেসহোল্ডার ছাড়া মেসেজের জন্য (যেমন শুভেচ্ছা, অপেক্ষার মেসেজ)। কলে বাজানোর আগে নিজে থেকে ফোনের মানে বদলে নেওয়া হবে।')),
            Section::make(__('শুনে দেখুন'))->columnSpanFull()
                ->visible(fn (Get $get) => $get('source') !== 'upload')
                ->schema([
                    Select::make('preview_customer')->label(__('কাস্টমার (না বাছলে উদাহরণ)'))->dehydrated(false)->searchable()
                        ->getSearchResultsUsing(fn (string $search) => BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
                            ->matching(trim($search))->orderBy('customer_id')->limit(15)
                            ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
                            ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])->all())
                        ->getOptionLabelUsing(fn ($value) => ($c = BillingCustomer::where('company_id', static::company())->where('header_id', $value)->first())
                            ? BillingCustomer::searchLabel($c) : null),
                    Hidden::make('preview_url')->dehydrated(false),
                    Hidden::make('preview_text')->dehydrated(false),
                    Actions::make([
                        Action::make('preview')->label(__('শুনুন'))->icon(Heroicon::OutlinedPlay)
                            ->action(function (Get $get, Set $set) {
                                $c = $get('preview_customer')
                                    ? BillingCustomer::where('company_id', static::company())->where('header_id', $get('preview_customer'))->first() : null;
                                ['text' => $text, 'parts' => $parts] = VoiceTemplates::speech(static::company(), (string) $get('body'), VoiceTemplates::values(static::company(), $c));
                                try {
                                    $file = VoiceTemplates::audio(static::company(), $text, (string) ($get('voice') ?: 'Kore'), (float) ($get('speed') ?: 1), $parts);
                                } catch (RuntimeException $e) {
                                    Notification::make()->danger()->title($e->getMessage())->send();

                                    return;
                                }
                                $set('preview_text', $text);
                                $set('preview_url', VoiceTemplates::url(static::slug(), $file));
                            }),
                    ])->key('preview_actions'),
                    View::make('filament.app.voice.player')
                        ->viewData(fn (Get $get) => ['url' => $get('preview_url'), 'text' => $get('preview_text')]),
                ]),
            TextInput::make('sort')->label(__('ক্রম'))->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('কোনো ভয়েস টেমপ্লেট নেই'))
            ->columns([
                TextColumn::make('name')->label(__('নাম'))->searchable(),
                TextColumn::make('type')->label(__('ধরন'))->badge()
                    ->formatStateUsing(fn (string $state) => __(VoiceTemplate::TYPES[$state] ?? $state))
                    ->color(fn (string $state) => match ($state) {
                        'reminder', 'line_off' => 'success', 'whisper' => 'warning', 'general' => 'gray', default => 'info',
                    }),
                TextColumn::make('body')->label(__('লেখা'))->wrap()->limit(160)->searchable()
                    ->state(fn (VoiceTemplate $r) => $r->source === 'upload' ? __('🎙 নিজের রেকর্ড') : $r->body),
                TextColumn::make('voice')->label(__('কণ্ঠ'))
                    ->state(fn (VoiceTemplate $r) => $r->source === 'upload' ? '—'
                        : __(VoiceTemplate::VOICES[$r->voice] ?? $r->voice).' · '.__(VoiceTemplate::SPEEDS[number_format((float) $r->speed, 2, '.', '')] ?? $r->speed)),
            ])
            ->headerActions([
                Action::make('voice_all')->label(__('সবগুলোর কণ্ঠ বদলান'))->icon(Heroicon::OutlinedSpeakerWave)->color('gray')
                    ->modalHeading(__('সব লেখা-টেমপ্লেটের কণ্ঠ ও গতি একসাথে'))
                    ->modalDescription(__('নিজের রেকর্ড করা টেমপ্লেট বাদে সবগুলোতে বসবে। এরপর যে অডিও লাগবে, নতুন কণ্ঠে বানানো হবে।'))
                    ->schema([
                        Select::make('voice')->label(__('কণ্ঠ'))->options(array_map('__', VoiceTemplate::VOICES))->required(),
                        Select::make('speed')->label(__('গতি'))->options(array_map('__', VoiceTemplate::SPEEDS))->placeholder(__('যেমন আছে')),
                    ])
                    ->action(function (array $data) {
                        $n = VoiceTemplate::where('company_id', static::company())->where('source', '!=', 'upload')
                            ->update(array_filter(['voice' => $data['voice'], 'speed' => $data['speed'] ?? null]) + ['updated_at' => now()]);
                        Notification::make()->success()->title(__(':n টা টেমপ্লেটে বদলানো হলো', ['n' => $n]))->send();
                    }),
                CreateAction::make()->label(__('টেমপ্লেট যোগ করুন'))
                    ->mutateDataUsing(fn (array $data) => static::clean([...$data, 'company_id' => static::company()])),
            ])
            ->recordActions([
                Action::make('listen')->label(__('শুনুন'))->icon(Heroicon::OutlinedPlay)->color('gray')
                    ->modalHeading(fn (VoiceTemplate $r) => $r->name)
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ'))
                    ->modalContent(function (VoiceTemplate $r) {
                        if ($r->source === 'upload') {
                            return view('filament.app.voice.player', ['url' => $r->file() ? VoiceTemplates::uploadUrl(static::slug(), $r) : null,
                                'text' => $r->file() ? null : __('রেকর্ড ফাইল পাওয়া যায়নি')]);
                        }
                        ['text' => $text, 'parts' => $parts] = VoiceTemplates::speech(static::company(), (string) $r->body, VoiceTemplates::values(static::company(), null));
                        try {
                            $url = VoiceTemplates::url(static::slug(), VoiceTemplates::audio(static::company(), $text, $r->voice, (float) $r->speed, $parts));
                        } catch (RuntimeException $e) {
                            return view('filament.app.voice.player', ['url' => null, 'text' => $e->getMessage()]);
                        }

                        return view('filament.app.voice.player', ['url' => $url, 'text' => $text.' '.__('(উদাহরণ কাস্টমার)')]);
                    }),
                EditAction::make()->mutateDataUsing(fn (array $data) => static::clean($data)),
                DeleteAction::make(),
            ]);
    }

    /** One source per template: text for TTS, or the recording. */
    private static function clean(array $data): array
    {
        if (($data['source'] ?? 'tts') === 'upload') {
            $data['body'] = null;
        } else {
            $data['audio_path'] = null;
        }

        return $data;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company());
    }

    public static function getPages(): array
    {
        return ['index' => ManageVoiceTemplates::route('/')];
    }
}
