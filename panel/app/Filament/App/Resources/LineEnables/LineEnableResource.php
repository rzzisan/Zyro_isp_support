<?php

namespace App\Filament\App\Resources\LineEnables;

use App\Filament\App\Resources\LineEnables\Pages\ListLineEnables;
use App\Models\LineEnable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Log: which technician asked the bot to turn on which customer's line, and the result. Read-only. */
class LineEnableResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = LineEnable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPower;

    public static function getNavigationLabel(): string
    {
        return __('লাইন চালু/বন্ধের রেকর্ড');
    }

    public static function getModelLabel(): string
    {
        return __('লাইন চালু');
    }

    public static function getPluralModelLabel(): string
    {
        return __('লাইন চালু/বন্ধের রেকর্ড');
    }

    protected static ?string $slug = 'line-enables';

    protected static string|\UnitEnum|null $navigationGroup = 'কাস্টমার ও বিল';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('line_enables');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return $table
            ->poll('30s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('technician_name')->label(__('টেকনিশিয়ান'))
                    ->description(fn (LineEnable $r) => $r->technician_number && str_starts_with($r->technician_number, '880') ? '0'.substr($r->technician_number, 3) : $r->technician_number)
                    ->searchable(),
                TextColumn::make('customer_name')->label(__('কাস্টমার'))
                    ->description(fn (LineEnable $r) => trim('ID '.$r->customer_id.($r->username ? ' · '.$r->username : '')))
                    ->searchable(['customer_name', 'customer_id', 'username']),
                TextColumn::make('action')->label(__('অনুরোধ'))->badge()
                    ->formatStateUsing(fn (string $state) => __(LineEnable::ACTIONS[$state] ?? $state))
                    ->color(fn (string $state) => $state === 'disable' ? 'danger' : 'success'),
                TextColumn::make('due')->label(__('তখন বকেয়া'))->placeholder('—')->suffix(__(' টাকা')),
                TextColumn::make('result')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn (string $state) => __(LineEnable::RESULTS[$state] ?? $state))
                    ->color(fn (string $state) => ['enabled' => 'success', 'disabled' => 'success', 'failed' => 'danger', 'not_allowed' => 'danger', 'dry_run' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('request')->label(__('টেকনিশিয়ানের মেসেজ'))->limit(60)->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('technician_name')->label(__('টেকনিশিয়ান'))
                    ->options(fn () => LineEnable::where('company_id', $company())->distinct()->orderBy('technician_name')
                        ->pluck('technician_name', 'technician_name')->all()),
                SelectFilter::make('action')->label(__('অনুরোধ'))->options(array_map('__', LineEnable::ACTIONS)),
                SelectFilter::make('result')->label(__('ফল'))->options(array_map('__', LineEnable::RESULTS)),
                Filter::make('date')->schema([DatePicker::make('from')->label(__('থেকে')), DatePicker::make('until')->label(__('পর্যন্ত'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc()))),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListLineEnables::route('/')];
    }
}
