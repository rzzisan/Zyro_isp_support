<?php

namespace App\Filament\App\Resources\VoiceFlows;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\VoiceFlows\Pages\ManageVoiceFlows;
use App\Models\BillingCustomer;
use App\Models\SmsTemplate;
use App\Models\VoiceFlow;
use App\Models\VoiceTemplate;
use App\Support\Menu;
use App\Support\VoiceCalls;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Voice reminder call flows: which messages play, in which order, and what each key does (SMS, line on, staff,
 * ticket, no more calls ...). The voice bot runs them (engine/voice_ivr.py). "টেস্ট কল" calls one number now.
 */
class VoiceFlowResource extends Resource
{
    use QuickAccessible;

    protected static ?string $model = VoiceFlow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected static ?string $slug = 'voice-flows';

    protected static string|\UnitEnum|null $navigationGroup = 'ভয়েস রিমাইন্ডার';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('কল ফ্লো');
    }

    public static function getModelLabel(): string
    {
        return __('কল ফ্লো');
    }

    public static function getPluralModelLabel(): string
    {
        return __('কল ফ্লো');
    }

    public static function canAccess(): bool
    {
        return Menu::can('voice_reminder');
    }

    private static function company(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }

    private static function voiceOptions(?string $type = null): array
    {
        return VoiceTemplate::where('company_id', static::company())->orderBy('sort')->orderBy('id')->get(['id', 'name', 'type'])
            ->mapWithKeys(fn ($t) => [$t->id => $t->name.' · '.__(VoiceTemplate::TYPES[$t->type] ?? $t->type)])->all();
    }

    /** Step names typed so far, for "then go to" (from inside a key: up out of the key and the step lists). */
    private static function stepNames(Get $get): array
    {
        return collect($get('../../../../steps') ?? [])->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()
            ->mapWithKeys(fn ($n) => [$n => $n])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $needsSay = fn (Get $get) => in_array($get('action'), ['sms', 'reconnect', 'ticket', 'optout', 'end'], true);
        $needsNext = fn (Get $get) => in_array($get('action'), ['goto', 'sms', 'reconnect', 'ticket'], true);

        return $schema->components([
            Grid::make(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('নাম'))->required()->maxLength(100),
                Select::make('kind')->label(__('কোন কলে'))->options(array_map('__', VoiceFlow::KINDS))->default('general')
                    ->selectablePlaceholder(false)->required()
                    ->helperText(__('পরে রিমাইন্ডার সেটিংসে কোন কলে কোন ফ্লো চলবে বাছবেন')),
            ]),
            Repeater::make('steps')->label(__('ধাপ (কল প্রথম ধাপ দিয়ে শুরু হয়)'))->columnSpanFull()->reorderable()->collapsible()
                ->itemLabel(fn (array $state) => $state['name'] ?? null)->minItems(1)->defaultItems(1)
                ->addActionLabel(__('ধাপ যোগ করুন'))
                ->schema([
                    Grid::make(4)->schema([
                        TextInput::make('name')->label(__('ধাপের নাম'))->required()->maxLength(40)->live(onBlur: true)
                            ->placeholder(__('যেমন: শুরু, মেনু')),
                        Select::make('templates')->label(__('যে মেসেজ বাজবে (ক্রমে)'))->multiple()->required()
                            ->options(fn () => static::voiceOptions())->columnSpan(3),
                        TextInput::make('wait')->label(__('বাটনের জন্য অপেক্ষা (সেকেন্ড)'))->numeric()->minValue(2)->maxValue(30)->default(7)->required(),
                        TextInput::make('repeats')->label(__('বাটন না চাপলে আবার বাজবে (বার)'))->numeric()->minValue(0)->maxValue(5)->default(2)->required(),
                        Select::make('no_input_template_id')->label(__('বাটন না চাপলে বলবে'))->options(fn () => static::voiceOptions()),
                        Select::make('invalid_template_id')->label(__('ভুল বাটনে বলবে'))->options(fn () => static::voiceOptions()),
                    ]),
                    Repeater::make('keys')->label(__('বাটন'))->addActionLabel(__('বাটন যোগ করুন'))->defaultItems(0)
                        ->itemLabel(fn (array $state) => isset($state['digit']) ? $state['digit'].' → '.__(VoiceFlow::ACTIONS[$state['action'] ?? ''] ?? '') : null)
                        ->schema([
                            Grid::make(4)->schema([
                                Select::make('digit')->label(__('বাটন'))->options(array_combine(VoiceFlow::KEYS, VoiceFlow::KEYS))->required(),
                                Select::make('action')->label(__('কী হবে'))->options(array_map('__', VoiceFlow::ACTIONS))->required()->live()->columnSpan(3),
                                Select::make('sms_template_id')->label(__('কোন SMS'))->columnSpan(2)
                                    ->options(fn () => SmsTemplate::where('company_id', static::company())->orderBy('sort')->pluck('name', 'id')->all())
                                    ->visible(fn (Get $get) => $get('action') === 'sms')->required(fn (Get $get) => $get('action') === 'sms'),
                                TextInput::make('problem')->label(__('টিকেটে সমস্যা'))->maxLength(200)->columnSpan(2)
                                    ->default('রিমাইন্ডার কল থেকে কাস্টমার যোগাযোগ চেয়েছেন')
                                    ->visible(fn (Get $get) => $get('action') === 'ticket'),
                                Select::make('say')->label(fn (Get $get) => $get('action') === 'reconnect' ? __('চালু হলে বলবে') : __('তারপর বলবে'))
                                    ->options(fn () => static::voiceOptions())->visible($needsSay)->columnSpan(2),
                                Select::make('say_fail')->label(__('চালু না হলে বলবে'))->options(fn () => static::voiceOptions())
                                    ->visible(fn (Get $get) => $get('action') === 'reconnect')->columnSpan(2),
                                Select::make('next')->label(fn (Get $get) => $get('action') === 'reconnect' ? __('চালু হলে যাবে') : __('তারপর যাবে'))
                                    ->options(fn (Get $get) => static::stepNames($get))->placeholder(__('কল শেষ'))
                                    ->visible($needsNext)->required(fn (Get $get) => $get('action') === 'goto')->columnSpan(2),
                                Select::make('next_fail')->label(__('চালু না হলে যাবে'))->options(fn (Get $get) => static::stepNames($get))
                                    ->placeholder(__('কল শেষ'))->visible(fn (Get $get) => $get('action') === 'reconnect')->columnSpan(2),
                            ]),
                        ]),
                ]),
            Section::make(__('স্টাফের সাথে কথা: অপেক্ষার সময়'))->columnSpanFull()->statePath('hold')->collapsible()
                ->description(__('কাস্টমার "স্টাফের সাথে কথা" চাইলে Desk অ্যাপ/ওয়েবে রিং হয় (যাদের "ফোন" অনুমতি আছে); ততক্ষণ কাস্টমার যা শোনেন'))
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('start_template_id')->label(__('শুরুতে বলবে'))->options(fn () => static::voiceOptions()),
                        Select::make('sound')->label(__('তারপর শব্দ'))->options(array_map('__', VoiceFlow::SOUNDS))->default('ring')->selectablePlaceholder(false),
                        Select::make('repeat_template_id')->label(__('মাঝে মাঝে বলবে'))->options(fn () => static::voiceOptions()),
                        TextInput::make('every')->label(__('কত সেকেন্ড পরপর'))->numeric()->minValue(10)->maxValue(120)->default(25),
                        TextInput::make('max_wait')->label(__('সর্বোচ্চ অপেক্ষা (সেকেন্ড)'))->numeric()->minValue(20)->maxValue(300)->default(90),
                        Select::make('after')->label(__('কেউ না ধরলে'))->options(array_map('__', VoiceFlow::AFTER))->default('voicemail')->selectablePlaceholder(false),
                        Select::make('voicemail_template_id')->label(__('ভয়েস মেসেজের আগে বলবে'))->options(fn () => static::voiceOptions()),
                        Select::make('callback_template_id')->label(__('কল-ব্যাকের আগে বলবে'))->options(fn () => static::voiceOptions()),
                    ]),
                ]),
            Select::make('goodbye_template_id')->label(__('কল শেষে বিদায়'))->options(fn () => static::voiceOptions()),
            TextInput::make('sort')->label(__('ক্রম'))->numeric()->default(0),
        ]);
    }

    /** Saving a flow whose keys lead nowhere would break calls: refuse, saying what is wrong. */
    public static function checked(array $data, Action $action): array
    {
        $problems = VoiceFlow::problems(array_values($data['steps'] ?? []));
        if ($problems) {
            Notification::make()->danger()->title(__('ফ্লো সেভ হয়নি'))->body(implode("\n", $problems))->persistent()->send();
            $action->halt();
        }
        $data['steps'] = array_values(array_map(fn ($s) => [...$s, 'name' => trim((string) $s['name']),
            'keys' => array_values(array_map(fn ($k) => [...$k, 'digit' => (string) $k['digit']], $s['keys'] ?? []))], $data['steps']));

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->emptyStateHeading(__('কোনো কল ফ্লো নেই'))
            ->columns([
                TextColumn::make('name')->label(__('নাম'))->searchable(),
                TextColumn::make('kind')->label(__('কোন কলে'))->badge()->formatStateUsing(fn ($state) => __(VoiceFlow::KINDS[$state] ?? $state)),
                TextColumn::make('steps')->label(__('ধাপ ও বাটন'))->wrap()
                    ->state(fn (VoiceFlow $r) => collect($r->steps ?? [])->map(fn ($s) => ($s['name'] ?? '').': '
                        .collect($s['keys'] ?? [])->map(fn ($k) => ($k['digit'] ?? '').' '.__(VoiceFlow::ACTIONS[$k['action'] ?? ''] ?? ''))->join(', '))->join(' | ')),
            ])
            ->headerActions([CreateAction::make()->label(__('ফ্লো যোগ করুন'))->slideOver()
                ->mutateDataUsing(fn (array $data, Action $action) => static::checked([...$data, 'company_id' => static::company()], $action))])
            ->recordActions([
                static::testCallAction(),
                EditAction::make()->slideOver()->mutateDataUsing(fn (array $data, Action $action) => static::checked($data, $action)),
                DeleteAction::make(),
            ]);
    }

    public static function testCallAction(): Action
    {
        return Action::make('testCall')->label(__('টেস্ট কল'))->icon(Heroicon::OutlinedPhone)->color('success')
            ->modalHeading(fn (VoiceFlow $r) => __('টেস্ট কল: :name', ['name' => $r->name]))
            ->modalDescription(__('এখনই এই নম্বরে কল যাবে, পুরো ফ্লো সহ। কাস্টমার বাছলে মেসেজে তার তথ্য বসবে; SMS, লাইন চালু, টিকেট তার নামেই হবে, তাই নিজের টেস্ট আইডি বাছুন।'))
            ->modalSubmitActionLabel(__('কল করুন'))
            ->schema([
                TextInput::make('number')->label(__('মোবাইল নম্বর'))->required()->tel()->placeholder('01XXXXXXXXX'),
                Select::make('customer')->label(__('কাস্টমার (না বাছলে উদাহরণ তথ্য)'))->searchable()
                    ->getSearchResultsUsing(fn (string $search) => BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
                        ->matching(trim($search))->orderBy('customer_id')->limit(15)
                        ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
                        ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])->all())
                    ->getOptionLabelUsing(fn ($value) => ($c = BillingCustomer::where('company_id', static::company())->where('header_id', $value)->first())
                        ? BillingCustomer::searchLabel($c) : null),
            ])
            ->action(function (VoiceFlow $record, array $data) {
                $c = ! empty($data['customer'])
                    ? BillingCustomer::where('company_id', static::company())->where('header_id', $data['customer'])->first() : null;
                try {
                    VoiceCalls::test($record, (string) $data['number'], $c, Auth::id());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title(__('কল যাচ্ছে'))
                    ->body(__('ফলাফল "হটলাইন কল লগ"-এ "রিমাইন্ডার" লাইনে দেখা যাবে।'))->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company());
    }

    public static function getPages(): array
    {
        return ['index' => ManageVoiceFlows::route('/')];
    }
}
