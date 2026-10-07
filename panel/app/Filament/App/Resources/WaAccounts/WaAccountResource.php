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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** The company's WhatsApp business number(s): Meta ids, access token, bot on/off. Owner/Admin only. */
class WaAccountResource extends Resource
{
    protected static ?string $model = WaAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'WhatsApp';

    protected static ?string $modelLabel = 'WhatsApp নম্বর';

    protected static ?string $pluralModelLabel = 'WhatsApp নম্বর';

    protected static ?string $slug = 'whatsapp';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('whatsapp') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone_number_id')->label('Phone number ID')->required()
                ->unique(ignoreRecord: true)
                ->helperText('Meta WhatsApp Manager → Phone numbers থেকে'),
            TextInput::make('waba_id')->label('WhatsApp Business Account ID')->required(),
            TextInput::make('access_token')->label('Access token')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে'),
            Toggle::make('bot_enabled')->label('এই নম্বরে বট চালু')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_phone_number')->label('নম্বর')->placeholder('পরীক্ষা করলে দেখাবে')
                    ->description(fn (WaAccount $r) => $r->verified_name),
                TextColumn::make('phone_number_id')->label('Phone number ID')->copyable(),
                TextColumn::make('waba_id')->label('WABA ID')->copyable(),
                IconColumn::make('bot_enabled')->label('বট')->boolean(),
                TextColumn::make('updated_at')->label('শেষ বদল')->since(),
            ])
            ->headerActions([CreateAction::make()->label('নম্বর যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([
                Action::make('test')->label('পরীক্ষা')->icon(Heroicon::OutlinedSignal)
                    ->action(function (WaAccount $record) {
                        try {
                            $info = WhatsApp::checkAccount($record);
                            Notification::make()->success()->title('সংযোগ ঠিক আছে')
                                ->body(trim(($info['display_phone_number'] ?? '').' · '.($info['verified_name'] ?? '')
                                    .(isset($info['quality_rating']) ? ' · Quality: '.$info['quality_rating'] : ''), ' ·'))
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('সংযোগ কাজ করছে না')->body($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make()->modalDescription('এই নম্বরের মেসেজ আর বট পাবে না। কনভারসেশন মুছবে না।'),
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
