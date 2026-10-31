<?php

namespace App\Filament\App\Resources\WalkieClips;

use App\Filament\App\Resources\WalkieClips\Pages\ListWalkieClips;
use App\Filament\App\Resources\WalkieGroups\WalkieGroupResource;
use App\Models\User;
use App\Models\WalkieClip;
use App\Models\WalkieGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** Every recorded walkie-talkie transmission and private call (engine/walkie.py writes them); the owner only. */
class WalkieClipResource extends Resource
{
    protected static ?string $model = WalkieClip::class;

    protected static ?string $slug = 'walkie-recordings';

    protected static bool $shouldRegisterNavigation = false;   // opened from the walkie-talkie page

    public static function getModelLabel(): string
    {
        return __('ওয়াকি-টকি রেকর্ড');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ওয়াকি-টকি রেকর্ড');
    }

    public static function canAccess(): bool
    {
        return WalkieGroupResource::owner();
    }

    public static function table(Table $table): Table
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label(__('সময়'))->dateTime('d M Y, h:i:s A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('where')->label(__('কোথায়'))
                    ->state(fn (WalkieClip $c) => $c->group?->name
                        ?? ($c->call ? __('প্রাইভেট কল').': '.$c->call->caller?->name.' → '.$c->call->callee?->name : '—')),
                TextColumn::make('user.name')->label(__('কে বললেন'))->placeholder('—'),
                TextColumn::make('seconds')->label(__('সেকেন্ড'))->numeric(1),
                TextColumn::make('audio')->label(__('শুনুন'))
                    ->state(fn (WalkieClip $c) => new HtmlString('<audio controls preload="none" style="height:32px" src="'
                        .e(route('walkie.clip', [Filament::getTenant(), $c])).'"></audio>')),
            ])
            ->filters([
                SelectFilter::make('walkie_group_id')->label(__('গ্রুপ'))
                    ->options(fn () => WalkieGroup::where('company_id', $company())->orderBy('name')->pluck('name', 'id')),
                Filter::make('calls')->label(__('শুধু প্রাইভেট কল'))->query(fn (Builder $query) => $query->whereNotNull('walkie_call_id')),
                SelectFilter::make('user_id')->label(__('কে বললেন'))->searchable()
                    ->options(fn () => User::whereIn('id', WalkieClip::where('company_id', $company())->distinct()->pluck('user_id'))->pluck('name', 'id')),
                Filter::make('date')->schema([DatePicker::make('day')->label(__('তারিখ'))])
                    ->query(fn (Builder $query, array $data) => $query->when($data['day'] ?? null, fn ($w, $d) => $w->whereBetween('started_at',
                        [\Illuminate\Support\Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc(), \Illuminate\Support\Carbon::parse($d, 'Asia/Dhaka')->endOfDay()->utc()]))),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0)
            ->with(['group', 'user', 'call.caller', 'call.callee']);
    }

    public static function getPages(): array
    {
        return ['index' => ListWalkieClips::route('/')];
    }
}
