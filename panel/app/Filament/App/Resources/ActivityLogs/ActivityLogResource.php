<?php

namespace App\Filament\App\Resources\ActivityLogs;

use App\Filament\App\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Membership;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** কাজের লগ (plan 2.4ক): every write action of every user, bot and timer. Owner only, read-only. */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'activity-logs';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 8;

    public const SOURCES = ['panel' => 'প্যানেল', 'app' => 'অ্যাপ', 'api' => 'API', 'bot' => 'বট', 'timer' => 'timer', 'engine' => 'সিস্টেম', 'console' => 'সার্ভার'];

    public static function getNavigationLabel(): string
    {
        return __('কাজের লগ');
    }

    public static function getModelLabel(): string
    {
        return __('কাজ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('কাজের লগ');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && auth()->user()?->roleIn($company) === 'owner';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** "field: before → after" lines. */
    public static function changesText(?array $changes): string
    {
        return collect($changes ?? [])->map(function ($v, $k) {
            [$before, $after] = is_array($v) && array_is_list($v) && count($v) === 2 ? $v : [null, $v];
            $show = fn ($x) => $x === null || $x === '' ? '—' : (is_bool($x) ? ($x ? 'হ্যাঁ' : 'না') : (is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE)));

            return $k.': '.$show($before).' → '.$show($after);
        })->implode("\n");
    }

    public static function table(Table $table): Table
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i:s A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('user.name')->label(__('কে'))->placeholder(fn (ActivityLog $r) => $r->actor === 'bot' ? __('বট') : __('সিস্টেম'))
                    ->description(fn (ActivityLog $r) => __(self::SOURCES[$r->source] ?? $r->source).($r->ip ? ' · '.$r->ip : '')),
                TextColumn::make('action')->label(__('কাজ'))->badge()->searchable()
                    ->description(fn (ActivityLog $r) => $r->subject_type ? $r->subject_type.' #'.$r->subject_id : null),
                TextColumn::make('customer_code')->label(__('কাস্টমার'))->placeholder('—')->searchable(),
                TextColumn::make('changes')->label(__('আগে → পরে'))
                    ->state(fn (ActivityLog $r) => self::changesText($r->changes))
                    ->formatStateUsing(fn ($state) => nl2br(e($state)))->html()->wrap()->lineClamp(6),
                TextColumn::make('note')->label(__('কারণ / নোট'))->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('device')->label(__('ডিভাইস'))->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label(__('ইউজার'))
                    ->options(fn () => Membership::where('company_id', $company())->with('user')->get()
                        ->mapWithKeys(fn ($m) => [$m->user_id => $m->user?->name])->filter()->all()),
                SelectFilter::make('action')->label(__('কাজের ধরন'))
                    ->options(fn () => ActivityLog::where('company_id', $company())->where('created_at', '>', now()->subDays(90))
                        ->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                SelectFilter::make('source')->label(__('কোথা থেকে'))->options(array_map('__', self::SOURCES)),
                Filter::make('customer')->schema([TextInput::make('code')->label(__('কাস্টমার ID'))])
                    ->query(fn (Builder $query, array $data) => $query->when($data['code'] ?? null, fn ($q, $c) => $q->where('customer_code', trim($c)))),
                Filter::make('date')->schema([DatePicker::make('from')->label(__('থেকে')), DatePicker::make('until')->label(__('পর্যন্ত'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc()))),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0)->with('user');
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
