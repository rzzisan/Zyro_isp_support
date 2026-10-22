<?php

namespace App\Filament\App\Resources\WaAccounts;

use App\Filament\App\Resources\WaAccounts\Pages\ManageWaAccounts;
use App\Models\WaAccount;
use App\Services\WhatsApp;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** The company's WhatsApp business number(s): Meta ids, access token, bot on/off, WhatsApp calls. Owner/Admin only. */
class WaAccountResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = WaAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'WhatsApp';

    public static function getModelLabel(): string
    {
        return __('WhatsApp নম্বর');
    }

    public static function getPluralModelLabel(): string
    {
        return __('WhatsApp নম্বর');
    }

    protected static ?string $slug = 'whatsapp';

    protected static string|\UnitEnum|null $navigationGroup = 'WhatsApp ও AI';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('whatsapp') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone_number_id')->label('Phone number ID')->required()
                ->unique(ignoreRecord: true)
                ->helperText(__('Meta WhatsApp Manager → Phone numbers থেকে')),
            TextInput::make('waba_id')->label('WhatsApp Business Account ID')->required(),
            TextInput::make('access_token')->label('Access token')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? __('বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে')),
            Toggle::make('bot_enabled')->label(__('এই নম্বরে বট চালু'))->default(true),
            TextInput::make('utility_rate')->label(__('Utility মেসেজের রেট'))->numeric()->minValue(0)->suffix(__('টাকা'))
                ->helperText(__('শুধু "WhatsApp পাঠান"-এ আনুমানিক খরচ দেখাতে; Meta কার্ডে আসল চার্জ করে')),
            TextInput::make('marketing_rate')->label(__('Marketing মেসেজের রেট'))->numeric()->minValue(0)->suffix(__('টাকা')),
            Section::make(__('WhatsApp কল'))->collapsible()
                ->description(__('কাস্টমার WhatsApp-এ কল করলে আগে "ফোন" অনুমতির স্টাফদের অ্যাপে ও Desk-এ বাজে, কেউ না ধরলে বট ধরে। সব কল রেকর্ড হয়ে কল লগে থাকে। Meta-তে কলিং চালু থাকতে হবে।'))
                ->schema([
                    Select::make('calling_mode')->label(__('কল ধরবে'))->default('off')->selectablePlaceholder(false)
                        ->options(['off' => __('বন্ধ (কলারকে ব্যস্ত দেখাবে)'), 'test' => __('শুধু টেস্ট নম্বরের কল'), 'live' => __('সব কল')]),
                    Textarea::make('calling_test_numbers')->label(__('টেস্ট নম্বর'))->rows(2)
                        ->helperText(__('কমা বা নতুন লাইনে, যেমন 01711000000')),
                    TextInput::make('calling_bot_delay')->label(__('কত সেকেন্ড বাজার পর বট ধরবে'))->numeric()
                        ->minValue(0)->maxValue(45)->default(20)->suffix(__('সেকেন্ড'))
                        ->helperText(__('এই সময় "ফোন" অনুমতি থাকা স্টাফদের অ্যাপে ও Desk-এ কল বাজে, কেউ ধরলে বট ধরে না। ০ দিলে বট সাথে সাথে ধরে।')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_phone_number')->label(__('নম্বর'))->placeholder(__('পরীক্ষা করলে দেখাবে'))
                    ->description(fn (WaAccount $r) => $r->verified_name),
                TextColumn::make('phone_number_id')->label('Phone number ID')->copyable(),
                TextColumn::make('waba_id')->label('WABA ID')->copyable(),
                IconColumn::make('bot_enabled')->label(__('বট'))->boolean(),
                TextColumn::make('calling_mode')->label(__('কল'))->badge()
                    ->formatStateUsing(fn ($state) => ['off' => __('বন্ধ'), 'test' => __('টেস্ট'), 'live' => __('চালু')][$state] ?? $state)
                    ->color(fn ($state) => $state === 'live' ? 'success' : ($state === 'test' ? 'warning' : 'gray')),
                TextColumn::make('updated_at')->label(__('শেষ বদল'))->since(),
            ])
            ->headerActions([CreateAction::make()->label(__('নম্বর যোগ করুন'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([
                Action::make('test')->label(__('পরীক্ষা'))->icon(Heroicon::OutlinedSignal)
                    ->action(function (WaAccount $record) {
                        try {
                            $info = WhatsApp::checkAccount($record);
                            Notification::make()->success()->title(__('সংযোগ ঠিক আছে'))
                                ->body(trim(($info['display_phone_number'] ?? '').' · '.($info['verified_name'] ?? '')
                                    .(isset($info['quality_rating']) ? ' · Quality: '.$info['quality_rating'] : ''), ' ·'))
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('সংযোগ কাজ করছে না'))->body($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make()->modalDescription(__('এই নম্বরের মেসেজ আর বট পাবে না। কনভারসেশন মুছবে না।')),
            ]);
    }

    /** Explicit company scope in addition to Filament tenancy. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWaAccounts::route('/')];
    }
}
