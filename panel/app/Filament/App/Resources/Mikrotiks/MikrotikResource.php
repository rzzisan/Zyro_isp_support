<?php

namespace App\Filament\App\Resources\Mikrotiks;

use App\Filament\App\Resources\Mikrotiks\Pages\ManageMikrotiks;
use App\Models\BillingCustomer;
use App\Models\MikrotikRouter;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** MikroTik routers (RouterOS API) to see if a customer is online. Owner/Admin only. */
class MikrotikResource extends Resource
{
    protected static ?string $model = MikrotikRouter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'MikroTik';

    public static function getModelLabel(): string
    {
        return __('MikroTik রাউটার');
    }

    public static function getPluralModelLabel(): string
    {
        return __('MikroTik রাউটার');
    }

    protected static ?string $slug = 'mikrotik';

    protected static string|\UnitEnum|null $navigationGroup = 'নেটওয়ার্ক';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('mikrotik') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    /** Server names customers have in the billing software, with how many customers each. */
    public static function billingServers(): array
    {
        return BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')->where('is_left', false)
            ->whereNotNull('server')->where('server', '!=', 'Not Found')
            ->selectRaw('server, count(*) AS n')->groupBy('server')->orderByDesc('n')->pluck('n', 'server')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('host')->label(__('IP / হোস্ট'))->required()->placeholder(__('103.157.253.x বা 10.x.x.x')),
                TextInput::make('api_port')->label(__('API পোর্ট'))->numeric()->required()->default(8728)
                    ->helperText(__('সাধারণত 8728 (TLS হলে 8729)')),
                TextInput::make('username')->label(__('ইউজারনেম'))->required()
                    ->helperText(__('read-only API ইউজার দিলে ভালো (group=read)')),
                TextInput::make('password')->label(__('পাসওয়ার্ড'))->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে')),
            ]),
            Select::make('billing_server')->label(__('বিলিংয়ে এই রাউটারের নাম'))
                ->options(fn () => collect(static::billingServers())->mapWithKeys(fn ($n, $s) => [$s => "{$s} (".__(':n জন কাস্টমার', ['n' => $n]).')'])->all())
                ->placeholder(__('পরীক্ষা করলে নিজে মিলিয়ে নেবে'))
                ->helperText(__('বিলিং সফটওয়্যারে কাস্টমারের "Server" ঘরে যে নাম থাকে। খালি রাখলে রাউটারের identity নাম দিয়ে নিজে মেলাবে।')),
            Toggle::make('enabled')->label(__('চালু'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(function () {
                $servers = static::billingServers();
                $linked = MikrotikRouter::where('company_id', Filament::getTenant()->getKey())->whereNotNull('billing_server')->pluck('billing_server')->all();
                $missing = array_diff(array_keys($servers), $linked);

                return __('বিলিংয়ে সার্ভার: ').collect($servers)->map(fn ($n, $s) => "{$s} ({$n})")->join(', ')
                    .($missing ? __(' · এখনো যুক্ত হয়নি: ').implode(', ', $missing) : __(' · সবগুলো যুক্ত'));
            })
            ->columns([
                TextColumn::make('identity')->label(__('রাউটার (identity)'))->placeholder(__('পরীক্ষা করা হয়নি'))
                    ->description(fn (MikrotikRouter $r) => $r->version ? 'RouterOS '.$r->version : null),
                TextColumn::make('host')->label(__('IP : পোর্ট'))->state(fn (MikrotikRouter $r) => "{$r->host}:{$r->api_port}"),
                TextColumn::make('billing_server')->label(__('বিলিংয়ে নাম'))->placeholder(__('মেলেনি'))
                    ->description(fn (MikrotikRouter $r) => $r->billing_server ? __(':n জন কাস্টমার', ['n' => $r->customerCount()]) : null),
                TextColumn::make('ppp_active')->label(__('অনলাইন PPPoE'))->placeholder('—'),
                TextColumn::make('last_check_ok')->label(__('শেষ পরীক্ষা'))
                    ->state(fn (MikrotikRouter $r) => $r->last_checked_at === null ? '—' : ($r->last_check_ok ? __('✅ ঠিক আছে') : '❌ '.$r->last_check_message))
                    ->description(fn (MikrotikRouter $r) => $r->last_checked_at?->diffForHumans())->wrap(),
            ])
            ->headerActions([CreateAction::make()->label(__('MikroTik যোগ করুন'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])
                ->after(fn (MikrotikRouter $record) => static::test($record))])
            ->recordActions([
                Action::make('test')->label(__('পরীক্ষা'))->icon(Heroicon::OutlinedSignal)->action(fn (MikrotikRouter $record) => static::test($record)),
                Action::make('backupNow')->label(__('এখনই ব্যাকআপ'))->icon(Heroicon::OutlinedArrowDownTray)
                    ->requiresConfirmation()->modalDescription(__('রাউটারে export আর backup ফাইল বানিয়ে Desk-এ নামিয়ে আনবে, তারপর রাউটার থেকে ফাইল মুছে দেবে।'))
                    ->action(fn (MikrotikRouter $record) => \App\Filament\App\Resources\MikrotikBackups\MikrotikBackupResource::backupNow($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function test(MikrotikRouter $record): void
    {
        try {
            $r = Engine::testMikrotik($record->company_id, $record->id);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('সংযোগ হয়নি'))->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title(__('সংযোগ ঠিক আছে: :name', ['name' => $r['identity']]))
            ->body(__('RouterOS :version · :n PPPoE অনলাইন · বিলিংয়ে নাম: :name', ['version' => $r['version'], 'n' => $r['ppp_active'],
                        'name' => $r['billing_server'] ?? __('মেলেনি, হাতে বেছে দিন')]))
            ->send();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMikrotiks::route('/')];
    }
}
