<?php

namespace App\Filament\App\Resources\MikrotikBackups;

use App\Filament\App\Resources\MikrotikBackups\Pages\ManageMikrotikBackups;
use App\Models\MikrotikBackup;
use App\Models\MikrotikRouter;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use RuntimeException;

/** Nightly + on-demand MikroTik backups (.rsc with PPP secrets, binary .backup). Owner/Admin only. */
class MikrotikBackupResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = MikrotikBackup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'mikrotik-backups';

    protected static string|\UnitEnum|null $navigationGroup = 'নেটওয়ার্ক';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('MikroTik ব্যাকআপ');
    }

    public static function getModelLabel(): string
    {
        return __('MikroTik ব্যাকআপ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('MikroTik ব্যাকআপ');
    }

    public static function canAccess(): bool
    {
        // exports hold every PPPoE password: same rule as the MikroTik page (owner/admin with the MikroTik menu)
        return \App\Support\Menu::can('mikrotik') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll(fn () => static::getEloquentQuery()->where('status', 'running')->exists() ? '5s' : null)
            ->description(fn () => __('প্রতি রাত ৩টায় সব MikroTik-এর ব্যাকআপ নেওয়া হয় (.rsc export পাসওয়ার্ডসহ, আর .backup)। :days দিনের পুরনো ব্যাকআপ নিজে মুছে যায় (নেটওয়ার্ক সেটিংসে বদলানো যায়)।',
                ['days' => Filament::getTenant()?->mikrotik_backup_days ?? 30]))
            ->columns([
                TextColumn::make('router_name')->label(__('রাউটার'))->placeholder('—')->searchable(),
                TextColumn::make('started_at')->label(__('সময়'))->dateTime('d M Y, h:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (MikrotikBackup $b) => $b->trigger === 'manual' ? __('হাতে নেওয়া') : __('অটো')),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ok' => __('ঠিক আছে'), 'partial' => __('আংশিক'), 'failed' => __('হয়নি'), default => __('চলছে…'),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'ok' => 'success', 'partial' => 'warning', 'failed' => 'danger', default => 'info',
                    }),
                TextColumn::make('rsc_size')->label('.rsc')->placeholder('—')->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 1)),
                TextColumn::make('backup_size')->label('.backup')->placeholder('—')->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 1)),
                TextColumn::make('error')->label(__('সমস্যা'))->placeholder('—')->wrap()->limit(200),
            ])
            ->filters([
                SelectFilter::make('router_id')->label(__('রাউটার'))->options(fn () => static::routers()),
            ])
            ->headerActions([
                Action::make('backupNow')->label(__('এখনই ব্যাকআপ'))->icon(Heroicon::OutlinedArrowDownTray)
                    ->schema([Select::make('router_id')->label(__('রাউটার'))->options(fn () => static::routers())->required()
                        ->default(fn () => count(static::routers()) === 1 ? array_key_first(static::routers()) : null)])
                    ->action(fn (array $data) => static::backupNow(MikrotikRouter::where('company_id', Filament::getTenant()->getKey())->findOrFail($data['router_id']))),
            ])
            ->recordActions([
                Action::make('rsc')->label('.rsc')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->visible(fn (MikrotikBackup $b) => (bool) $b->rsc_path)
                    ->url(fn (MikrotikBackup $b) => route('mikrotik-backups.download', [Filament::getTenant(), $b, 'rsc'])),
                Action::make('backup')->label('.backup')->icon(Heroicon::OutlinedArchiveBoxArrowDown)->color('gray')
                    ->visible(fn (MikrotikBackup $b) => (bool) $b->backup_path)
                    ->url(fn (MikrotikBackup $b) => route('mikrotik-backups.download', [Filament::getTenant(), $b, 'backup'])),
            ]);
    }

    /** [id => name] of this company's routers. */
    public static function routers(): array
    {
        return MikrotikRouter::where('company_id', Filament::getTenant()?->getKey() ?? 0)->orderBy('id')->get()
            ->mapWithKeys(fn (MikrotikRouter $r) => [$r->id => $r->identity ?: $r->host])->all();
    }

    /** Ask the engine to back up one router now (it runs in the background; the list polls). */
    public static function backupNow(MikrotikRouter $router): void
    {
        try {
            $r = Engine::backupMikrotik($router->company_id, $router->id);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('ব্যাকআপ শুরু হয়নি'))->body($e->getMessage())->send();

            return;
        }
        if ($r['running'] ?? false) {
            Notification::make()->warning()->title(__('এই রাউটারের ব্যাকআপ এখন চলছে'))->send();

            return;
        }
        Notification::make()->success()->title(__('ব্যাকআপ শুরু হয়েছে: :name', ['name' => $router->identity ?: $router->host]))
            ->body(__('এক-দুই মিনিট লাগতে পারে। MikroTik ব্যাকআপ পেজে দেখা যাবে।'))->send();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMikrotikBackups::route('/')];
    }
}
