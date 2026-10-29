<?php

namespace App\Filament\App\Resources\WalkieGroups;

use App\Filament\App\Resources\WalkieGroups\Pages\ManageWalkieGroups;
use App\Models\Membership;
use App\Models\WalkieGroup;
use App\Support\Walkie;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** Walkie-talkie groups of the Desk app: the owner chooses who is in each (App\Support\Walkie). */
class WalkieGroupResource extends Resource
{
    protected static ?string $model = WalkieGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $slug = 'walkie-groups';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('ওয়াকি-টকি');
    }

    public static function getModelLabel(): string
    {
        return __('ওয়াকি-টকি গ্রুপ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ওয়াকি-টকি গ্রুপ');
    }

    /** The owner only: they choose the members and may hear the recordings. */
    public static function owner(): bool
    {
        $company = Filament::getTenant();

        return $company !== null && auth()->user()?->roleIn($company) === 'owner';
    }

    public static function canAccess(): bool
    {
        return static::owner();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('গ্রুপের নাম'))->required()->maxLength(100)->placeholder(__('যেমন লাইন টিম, অফিস'))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', Filament::getTenant()->getKey())),
            Select::make('memberships')->label(__('সদস্য'))->multiple()->preload()->searchable()
                ->relationship('memberships', 'id', fn (Builder $query) => $query->where('company_user.company_id', Filament::getTenant()->getKey())->with('user'))
                ->getOptionLabelFromRecordUsing(fn (Membership $m) => $m->user?->name.' ('.(Membership::ROLES[$m->role] ?? $m->role).')')
                ->helperText(__('এঁরা অ্যাপে গ্রুপের সব কথা শুনবেন, চেপে ধরে কথা বলবেন, আর একে অপরকে প্রাইভেট কল দিতে পারবেন। টিম চ্যাটেও এটা এঁদের গ্রুপ চ্যাট। আপনি নিজে থাকতে চাইলে নিজেকেও যোগ করুন।')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(Walkie::configured()
                ? __('Desk অ্যাপের ওয়াকি-টকি। প্রতিদিন চালুর সময়ে সদস্যদের অ্যাপে নিজে থেকে চালু হয়; প্রত্যেকে নিজে বন্ধ/চালু করতে পারেন। সব কথা রেকর্ড থাকে (উপরে "রেকর্ড")।')
                : __('ওয়াকি-টকি সার্ভার (LiveKit) এখনো সেট করা হয়নি; গ্রুপ বানিয়ে রাখা যাবে, অ্যাপে দেখাবে সার্ভার চালু হলে।'))
            ->columns([
                TextColumn::make('name')->label(__('গ্রুপ'))->weight('medium')->searchable(),
                TextColumn::make('memberships.user.name')->label(__('সদস্য'))->badge(),
                TextColumn::make('online')->label(__('এখন অনলাইন'))
                    ->state(function (WalkieGroup $r) {
                        $ids = Walkie::online($r->room());

                        return $r->memberships->filter(fn ($m) => in_array($m->user_id, $ids, true))->map(fn ($m) => $m->user?->name)->filter()->values()->all();
                    })
                    ->badge()->color('success')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label(__('গ্রুপ যোগ করুন'))
                    ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0)->with('memberships.user');
    }

    public static function getPages(): array
    {
        return ['index' => ManageWalkieGroups::route('/')];
    }
}
