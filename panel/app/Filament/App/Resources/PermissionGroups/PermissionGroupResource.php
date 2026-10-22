<?php

namespace App\Filament\App\Resources\PermissionGroups;

use App\Filament\App\Resources\PermissionGroups\Pages\ManagePermissionGroups;
use App\Models\Membership;
use App\Models\PermissionGroup;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** Permission groups: menus given to a whole group of members at once (who manages the team page manages these). */
class PermissionGroupResource extends Resource
{
    protected static ?string $model = PermissionGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function getNavigationLabel(): string
    {
        return __('পারমিশন গ্রুপ');
    }

    public static function getModelLabel(): string
    {
        return __('পারমিশন গ্রুপ');
    }

    public static function getPluralModelLabel(): string
    {
        return __('পারমিশন গ্রুপ');
    }

    protected static ?string $slug = 'permission-groups';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return Menu::can('team') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('গ্রুপের নাম'))->required()->maxLength(100)->placeholder(__('যেমন টেকনিশিয়ান, অফিস স্টাফ'))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', Filament::getTenant()->getKey())),
            CheckboxList::make('permissions')->label(__('এই গ্রুপের সদস্যরা কোন কোন মেনু দেখবেন'))
                ->options(Menu::options())->columns(3)->bulkToggleable()->default([])
                ->helperText(__('সেটিংসের মেনুগুলো (WhatsApp, বিলিং সংযোগ, MikroTik, বট, AI key, টিম, টেকনিশিয়ান) শুধু Admin সদস্যের জন্য কাজ করে।')),
            Select::make('memberships')->label(__('সদস্য'))->multiple()->preload()->searchable()
                ->relationship('memberships', 'id', fn (Builder $query) => $query->where('company_user.company_id', Filament::getTenant()->getKey())
                    ->where('company_user.role', '!=', 'owner')->with('user'))
                ->getOptionLabelFromRecordUsing(fn (Membership $m) => $m->user?->name.' ('.(Membership::ROLES[$m->role] ?? $m->role).')')
                ->helperText(__('Owner সব মেনু এমনিতেই দেখেন, তাই তালিকায় নেই। একজন সদস্য একাধিক গ্রুপে থাকতে পারেন; সব গ্রুপের মেনু আর টিম পেজে তাঁর নিজের টিক দেওয়া মেনু মিলিয়ে দেখবেন।')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('গ্রুপে মেনু টিক দিন, তারপর সদস্য যোগ করুন: গ্রুপের সব সদস্য সেই মেনুগুলো পাবেন। গ্রুপের মেনু বদলালে সবার জন্য একসাথে বদলায়।'))
            ->columns([
                TextColumn::make('name')->label(__('গ্রুপ'))->weight('medium')->searchable(),
                TextColumn::make('menus')->label(__('মেনু'))
                    ->state(fn (PermissionGroup $r) => count($r->permissions ?? []).'/'.count(Menu::ITEMS))
                    ->tooltip(fn (PermissionGroup $r) => collect($r->permissions ?? [])->map(fn ($k) => isset(Menu::ITEMS[$k]) ? __(Menu::ITEMS[$k][0]) : $k)->join(', ')),
                TextColumn::make('memberships.user.name')->label(__('সদস্য'))->badge(),
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
        return ['index' => ManagePermissionGroups::route('/')];
    }
}
