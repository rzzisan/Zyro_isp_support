<?php

namespace App\Filament\Super\Resources\SystemBackups;

use App\Filament\Super\Resources\SystemBackups\Pages\ManageSystemBackups;
use App\Models\SystemBackup;
use App\Models\SystemBackupSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * Whole-Desk backups on Google Drive: history, back up now, restore test, download. Super admin only.
 * The panel only queues rows; engine/system_backup.py (root, every minute) runs them and writes the result.
 */
class SystemBackupResource extends Resource
{
    protected static ?string $model = SystemBackup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static ?string $slug = 'system-backups';

    protected static string|\UnitEnum|null $navigationGroup = 'সিস্টেম';

    protected static ?int $navigationSort = 1;

    public const KINDS = ['full' => 'পুরো', 'db' => 'ডাটাবেস', 'restore_test' => 'রিস্টোর টেস্ট', 'download' => 'ডাউনলোড'];

    public const TIERS = ['hourly' => 'ঘণ্টার', 'daily' => 'দৈনিক', 'weekly' => 'সাপ্তাহিক', 'monthly' => 'মাসিক', 'manual' => 'হাতে'];

    public static function getNavigationLabel(): string
    {
        return __('সিস্টেম ব্যাকআপ');
    }

    public static function getModelLabel(): string
    {
        return __('ব্যাকআপ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('সিস্টেম ব্যাকআপ');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll(fn () => SystemBackup::busy() ? '5s' : null)
            ->columns([
                TextColumn::make('kind')->label(__('ধরন'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => __(static::KINDS[$state] ?? $state))
                    ->description(fn (SystemBackup $b) => $b->trigger === 'manual' ? __('হাতে') : __('অটো')),
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, h:i A', 'Asia/Dhaka')->sortable()
                    ->description(fn (SystemBackup $b) => $b->finished_at && $b->started_at
                        ? __(':s সেকেন্ড', ['s' => (int) $b->started_at->diffInSeconds($b->finished_at)]) : null),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ok' => __('ঠিক আছে'), 'partial' => __('আংশিক'), 'failed' => __('হয়নি'), 'queued' => __('লাইনে আছে'), default => __('চলছে…'),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'ok' => 'success', 'partial' => 'warning', 'failed' => 'danger', default => 'info',
                    }),
                TextColumn::make('size')->label(__('সাইজ'))->placeholder('—')->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 1)),
                TextColumn::make('tier')->label(__('রাখা হবে'))->placeholder('—')
                    ->formatStateUsing(fn (?string $state, SystemBackup $b) => $b->pruned ? __('মুছে গেছে') : __(static::TIERS[$state] ?? $state)),
                TextColumn::make('details')->label(__('বিস্তারিত'))->state(fn (SystemBackup $b) => static::details($b))->wrap()->placeholder('—'),
                TextColumn::make('error')->label(__('সমস্যা'))->placeholder('—')->wrap()->limit(300),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('ধরন'))->options(collect(static::KINDS)->map(fn ($l) => __($l))->all()),
                SelectFilter::make('status')->label(__('অবস্থা'))->options(['ok' => __('ঠিক আছে'), 'partial' => __('আংশিক'), 'failed' => __('হয়নি')]),
            ])
            ->recordActions([
                Action::make('prepareDownload')->label(__('ডাউনলোড'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (SystemBackup $b) => $b->restorable())
                    ->requiresConfirmation()->modalHeading(__('ব্যাকআপ ডাউনলোড'))
                    ->modalDescription(__('সার্ভার Drive থেকে এনে ডিক্রিপ্ট করে একটা .tar বানাবে (এক-দুই মিনিট)। এতে কাস্টমার ডাটা আর গোপন চাবি খোলা অবস্থায় থাকে, ২ ঘণ্টা পর সার্ভার থেকে মুছে যায়।'))
                    ->action(fn (SystemBackup $b) => static::enqueue('download', $b)),
                Action::make('restoreTest')->label(__('রিস্টোর টেস্ট'))->icon(Heroicon::OutlinedBeaker)->color('gray')
                    ->visible(fn (SystemBackup $b) => $b->restorable())
                    ->requiresConfirmation()
                    ->modalDescription(__('এই ব্যাকআপ একটা আলাদা টেস্ট ডাটাবেসে বসিয়ে লাইভের সাথে মিলিয়ে দেখবে, তারপর টেস্ট ডাটাবেস মুছে ফেলবে। লাইভে কিছু বদলাবে না।'))
                    ->action(fn (SystemBackup $b) => static::enqueue('restore_test', $b)),
                Action::make('getFile')->label(__('ফাইল নিন'))->icon(Heroicon::OutlinedDocumentArrowDown)->color('success')
                    ->visible(fn (SystemBackup $b) => $b->kind === 'download' && $b->download_path && $b->expires_at?->isFuture())
                    ->url(fn (SystemBackup $b) => route('system-backup.download', $b)),
                Action::make('howRestore')->label(__('লাইভে রিস্টোর'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->visible(fn (SystemBackup $b) => $b->restorable())
                    ->modalHeading(__('লাইভ ডাটাবেস এই ব্যাকআপে ফেরত নেওয়া'))
                    ->modalDescription(fn (SystemBackup $b) => __('এটা লাইভের সব ডাটা এই ব্যাকআপের সময়ে ফিরিয়ে নেয়, তাই প্যানেলের বাটন দিয়ে নয়, সার্ভারে এই কমান্ড দিয়ে করতে হয়। আগে লাইভের একটা কপি নেয় আর পুরনো ডাটাবেস মোছে না, নাম বদলে রেখে দেয়। আগে "রিস্টোর টেস্ট" চালিয়ে দেখে নিন।')
                        ."\n\ncd /var/www/zyro_isp_support && sudo -E bash -c 'set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.system_backup restore-db {$b->id} --yes'")
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ')),
            ]);
    }

    /** Buttons above the list. */
    public static function headerActions(): array
    {
        return [
            Action::make('backupNow')->label(__('এখনই ব্যাকআপ'))->icon(Heroicon::OutlinedCloudArrowUp)
                ->disabled(fn () => ! SystemBackupSetting::current()->connected())
                ->schema([Radio::make('kind')->label(__('কী নেবে'))->required()->default('full')
                    ->options(['full' => __('পুরো (ডাটাবেস + ফাইল + গোপন + কনফিগ + কোড)'), 'db' => __('শুধু ডাটাবেস')])])
                ->action(fn (array $data) => static::enqueue($data['kind'])),
            Action::make('restoreTestLatest')->label(__('রিস্টোর টেস্ট (সর্বশেষ)'))->icon(Heroicon::OutlinedBeaker)->color('gray')
                ->disabled(fn () => ! SystemBackupSetting::current()->connected())
                ->requiresConfirmation()
                ->modalDescription(__('সর্বশেষ ব্যাকআপ একটা আলাদা টেস্ট ডাটাবেসে বসিয়ে লাইভের সাথে মিলিয়ে দেখবে। লাইভে কিছু বদলাবে না।'))
                ->action(fn () => static::enqueue('restore_test')),
            Action::make('settings')->label(__('সেটিংস'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->url(fn () => \App\Filament\Super\Pages\SystemBackupSettings::getUrl(panel: 'super')),
        ];
    }

    public static function enqueue(string $kind, ?SystemBackup $source = null): void
    {
        abort_unless(static::canAccess(), 403);
        if (! SystemBackupSetting::current()->connected()) {
            Notification::make()->danger()->title(__('আগে Google Drive সংযোগ দিন'))->send();

            return;
        }
        if (SystemBackup::where('kind', $kind)->whereIn('status', ['queued', 'running'])->exists()) {
            Notification::make()->warning()->title(__('এই কাজ এখন লাইনে বা চলছে'))->send();

            return;
        }
        SystemBackup::create(['kind' => $kind, 'trigger' => 'manual', 'status' => 'queued', 'source_id' => $source?->id,
            'requested_by' => auth()->id()]);
        Notification::make()->success()->title(__('শুরু হচ্ছে'))
            ->body(__('এক মিনিটের মধ্যে চালু হবে; এই তালিকায় অবস্থা দেখা যাবে।'))->send();
    }

    private static function details(SystemBackup $b): ?string
    {
        $p = $b->parts ?? [];
        $out = [];
        if (isset($p['db'])) {
            $out[] = __('ডাটাবেস :s', ['s' => Number::fileSize((int) $p['db'], 1)]);
        }
        foreach ($p['synced'] ?? [] as $dst => $f) {
            $out[] = ($dst === 'files/apk' ? 'APK' : __('ফাইল')).' '.($f['files'] ?? 0).' ('.Number::fileSize((int) ($f['bytes'] ?? 0), 1).')';
        }
        if (! empty($p['commit'])) {
            $out[] = 'commit '.substr($p['commit'], 0, 7).(! empty($p['dirty']) ? ' '.__('(অ-commit বদল আছে)') : '');
        }
        if (isset($p['tables'])) {
            $c = collect($p['tables'])->map(fn ($v, $t) => is_array($v) ? "{$t} ".($v['backup'] ?? '-').'/'.($v['live'] ?? '-') : "{$t} {$v}");
            $out[] = $b->kind === 'restore_test' ? __('ব্যাকআপ/লাইভ: ').$c->take(4)->implode(', ') : null;
        }
        if (isset($p['restore_seconds'])) {
            $out[] = __('রিস্টোর :s সেকেন্ড', ['s' => $p['restore_seconds']]);
        }
        if ($b->source_id && in_array($b->kind, ['restore_test', 'download'], true)) {
            $out[] = __('ব্যাকআপ #:id থেকে', ['id' => $b->source_id]);
        }

        return implode(' · ', array_filter($out)) ?: null;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSystemBackups::route('/')];
    }
}
