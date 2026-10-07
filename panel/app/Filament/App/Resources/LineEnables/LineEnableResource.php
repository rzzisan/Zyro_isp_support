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
    protected static ?string $model = LineEnable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'লাইন চালুর রেকর্ড';

    protected static ?string $modelLabel = 'লাইন চালু';

    protected static ?string $pluralModelLabel = 'লাইন চালুর রেকর্ড';

    protected static ?string $slug = 'line-enables';

    protected static ?int $navigationSort = -4;

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
                TextColumn::make('created_at')->label('সময়')->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('technician_name')->label('টেকনিশিয়ান')
                    ->description(fn (LineEnable $r) => $r->technician_number && str_starts_with($r->technician_number, '880') ? '0'.substr($r->technician_number, 3) : $r->technician_number)
                    ->searchable(),
                TextColumn::make('customer_name')->label('কাস্টমার')
                    ->description(fn (LineEnable $r) => trim('ID '.$r->customer_id.($r->username ? ' · '.$r->username : '')))
                    ->searchable(['customer_name', 'customer_id', 'username']),
                TextColumn::make('due')->label('তখন বকেয়া')->placeholder('—')->suffix(' টাকা'),
                TextColumn::make('result')->label('ফল')->badge()
                    ->formatStateUsing(fn (string $state) => LineEnable::RESULTS[$state] ?? $state)
                    ->color(fn (string $state) => ['enabled' => 'success', 'already_active' => 'gray', 'failed' => 'danger', 'dry_run' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('request')->label('টেকনিশিয়ানের মেসেজ')->limit(60)->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('technician_name')->label('টেকনিশিয়ান')
                    ->options(fn () => LineEnable::where('company_id', $company())->distinct()->orderBy('technician_name')
                        ->pluck('technician_name', 'technician_name')->all()),
                SelectFilter::make('result')->label('ফল')->options(LineEnable::RESULTS),
                Filter::make('date')->schema([DatePicker::make('from')->label('থেকে'), DatePicker::make('until')->label('পর্যন্ত')])
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
