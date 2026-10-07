<?php

namespace App\Filament\App\Resources\Olts;

use App\Filament\App\Resources\Olts\Pages\ManageOlts;
use App\Models\MikrotikRouter;
use App\Models\Olt;
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

/** The company's OLTs, read over SNMP (read-only community) for ONU status and power. Owner/Admin only. */
class OltResource extends Resource
{
    protected static ?string $model = Olt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'OLT';

    protected static ?string $modelLabel = 'OLT';

    protected static ?string $pluralModelLabel = 'OLT';

    protected static ?string $slug = 'olts';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 13;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('olt') && (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label('নাম')->required()->placeholder('যেমন CLN_1_TILARDI'),
                Select::make('brand')->label('ব্র্যান্ড')->options(Olt::BRANDS)->required()->default('bdcom'),
                TextInput::make('host')->label('IP')->required(),
                TextInput::make('snmp_port')->label('SNMP পোর্ট')->numeric()->default(161)->required(),
                TextInput::make('community')->label('SNMP community (read-only)')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'বদলাতে না চাইলে খালি রাখুন' : 'write community দেবেন না'),
                Select::make('router_identity')->label('এর কাস্টমার কোন MikroTik-এ')
                    ->options(fn () => MikrotikRouter::where('company_id', Filament::getTenant()->getKey())->whereNotNull('identity')
                        ->pluck('identity', 'identity')->all())
                    ->placeholder('সব রাউটার')
                    ->helperText('দিলে শুধু সেই রাউটারের কাস্টমারদের MAC এই OLT-এ খোঁজা হয় (OLT-এর উপর চাপ কম)'),
            ]),
            Toggle::make('enabled')->label('চালু')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('প্রতি ১০ মিনিটে প্রতিটা OLT থেকে সব ONU-র অবস্থা, optical power, distance পড়া হয় (শুধু পড়া, OLT-এ কিছু বদলায় না)। কাস্টমারের রাউটারের MAC দিয়ে তার ONU মেলানো হয়।')
            ->columns([
                TextColumn::make('name')->label('OLT')->description(fn (Olt $r) => $r->sys_name),
                TextColumn::make('brand')->label('ব্র্যান্ড')->formatStateUsing(fn ($state) => Olt::BRANDS[$state] ?? $state),
                TextColumn::make('host')->label('IP : পোর্ট')->state(fn (Olt $r) => "{$r->host}:{$r->snmp_port}"),
                TextColumn::make('router_identity')->label('MikroTik')->placeholder('সব'),
                TextColumn::make('onu_online')->label('ONU অনলাইন')
                    ->state(fn (Olt $r) => $r->onu_total === null ? '—' : "{$r->onu_online} / {$r->onu_total}"),
                TextColumn::make('last_poll_ok')->label('শেষ পড়া')
                    ->state(fn (Olt $r) => $r->last_poll_at === null ? '—' : ($r->last_poll_ok ? '✅ '.$r->last_poll_message : '❌ '.$r->last_poll_message))
                    ->description(fn (Olt $r) => $r->last_poll_at?->diffForHumans())->wrap(),
            ])
            ->headerActions([CreateAction::make()->label('OLT যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])
                ->after(fn (Olt $record) => static::test($record))])
            ->recordActions([
                Action::make('test')->label('পরীক্ষা')->icon(Heroicon::OutlinedSignal)->action(fn (Olt $record) => static::test($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function test(Olt $record): void
    {
        try {
            $r = Engine::testOlt($record->company_id, $record->id);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('SNMP সংযোগ হয়নি')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title("সংযোগ ঠিক আছে: {$r['sys_name']}")
            ->body($r['sys_descr'].(($r['supported'] ?? false) ? '' : ' · এই ব্র্যান্ডের ONU পড়া এখনো চালু হয়নি'))->send();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOlts::route('/')];
    }
}
