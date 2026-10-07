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

    protected static ?string $modelLabel = 'MikroTik রাউটার';

    protected static ?string $pluralModelLabel = 'MikroTik রাউটার';

    protected static ?string $slug = 'mikrotik';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('mikrotik') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    /** Server names customers have in the billing software, with how many customers each. */
    public static function billingServers(): array
    {
        return BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')
            ->whereNotNull('server')->where('server', '!=', 'Not Found')
            ->selectRaw('server, count(*) AS n')->groupBy('server')->orderByDesc('n')->pluck('n', 'server')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('host')->label('IP / হোস্ট')->required()->placeholder('103.157.253.x বা 10.x.x.x'),
                TextInput::make('api_port')->label('API পোর্ট')->numeric()->required()->default(8728)
                    ->helperText('সাধারণত 8728 (TLS হলে 8729)'),
                TextInput::make('username')->label('ইউজারনেম')->required()
                    ->helperText('read-only API ইউজার দিলে ভালো (group=read)'),
                TextInput::make('password')->label('পাসওয়ার্ড')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে'),
            ]),
            Select::make('billing_server')->label('বিলিংয়ে এই রাউটারের নাম')
                ->options(fn () => collect(static::billingServers())->mapWithKeys(fn ($n, $s) => [$s => "{$s} ({$n} জন কাস্টমার)"])->all())
                ->placeholder('পরীক্ষা করলে নিজে মিলিয়ে নেবে')
                ->helperText('বিলিং সফটওয়্যারে কাস্টমারের "Server" ঘরে যে নাম থাকে। খালি রাখলে রাউটারের identity নাম দিয়ে নিজে মেলাবে।'),
            Toggle::make('enabled')->label('চালু')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(function () {
                $servers = static::billingServers();
                $linked = MikrotikRouter::where('company_id', Filament::getTenant()->getKey())->whereNotNull('billing_server')->pluck('billing_server')->all();
                $missing = array_diff(array_keys($servers), $linked);

                return 'বিলিংয়ে সার্ভার: '.collect($servers)->map(fn ($n, $s) => "{$s} ({$n})")->join(', ')
                    .($missing ? ' · এখনো যুক্ত হয়নি: '.implode(', ', $missing) : ' · সবগুলো যুক্ত');
            })
            ->columns([
                TextColumn::make('identity')->label('রাউটার (identity)')->placeholder('পরীক্ষা করা হয়নি')
                    ->description(fn (MikrotikRouter $r) => $r->version ? 'RouterOS '.$r->version : null),
                TextColumn::make('host')->label('IP : পোর্ট')->state(fn (MikrotikRouter $r) => "{$r->host}:{$r->api_port}"),
                TextColumn::make('billing_server')->label('বিলিংয়ে নাম')->placeholder('মেলেনি')
                    ->description(fn (MikrotikRouter $r) => $r->billing_server ? $r->customerCount().' জন কাস্টমার' : null),
                TextColumn::make('ppp_active')->label('অনলাইন PPPoE')->placeholder('—'),
                TextColumn::make('last_check_ok')->label('শেষ পরীক্ষা')
                    ->state(fn (MikrotikRouter $r) => $r->last_checked_at === null ? '—' : ($r->last_check_ok ? '✅ ঠিক আছে' : '❌ '.$r->last_check_message))
                    ->description(fn (MikrotikRouter $r) => $r->last_checked_at?->diffForHumans())->wrap(),
            ])
            ->headerActions([CreateAction::make()->label('MikroTik যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])
                ->after(fn (MikrotikRouter $record) => static::test($record))])
            ->recordActions([
                Action::make('test')->label('পরীক্ষা')->icon(Heroicon::OutlinedSignal)->action(fn (MikrotikRouter $record) => static::test($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function test(MikrotikRouter $record): void
    {
        try {
            $r = Engine::testMikrotik($record->company_id, $record->id);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('সংযোগ হয়নি')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title("সংযোগ ঠিক আছে: {$r['identity']}")
            ->body("RouterOS {$r['version']} · {$r['ppp_active']} PPPoE অনলাইন · বিলিংয়ে নাম: ".($r['billing_server'] ?? 'মেলেনি, হাতে বেছে দিন'))
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
