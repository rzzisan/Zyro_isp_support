<?php

namespace App\Filament\App\Resources\Memberships;

use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Models\Membership;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Team: who works in this company and with which role. Owner/Admin only; owners can't be removed here. */
class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationLabel(): string
    {
        return __('টিম');
    }

    public static function getModelLabel(): string
    {
        return __('সদস্য');
    }

    public static function getPluralModelLabel(): string
    {
        return __('টিম');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('team') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    /** Admins may add agents/admins; only an owner may create another owner. */
    public static function roleOptions(): array
    {
        $roles = Membership::ROLES;
        if (auth()->user()?->roleIn(Filament::getTenant()) !== 'owner') {
            unset($roles['owner']);
        }

        return $roles;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('নাম'))->required()->visibleOn('create'),
            TextInput::make('email')->label(__('ইমেইল'))->email()->required()->visibleOn('create')
                ->helperText(__('এই ইমেইলে আগে থেকে অ্যাকাউন্ট থাকলে সেটাই যুক্ত হবে')),
            TextInput::make('password')->label(__('পাসওয়ার্ড'))->password()->revealable()->minLength(10)->visibleOn('create')
                ->helperText(__('নতুন ইউজার হলে লাগবে')),
            Select::make('role')->label(__('রোল'))->options(fn () => self::roleOptions())->required()->default('agent'),
            TextInput::make('billing_username')->label(__('বিলিং সফটওয়্যারের ইউজারনেম (ঐচ্ছিক)'))->visibleOn('edit')
                ->helperText(__('এই সদস্য প্যানেল থেকে টিকিট খুললে বা assign করলে এই লগইনে হবে। সদস্য নিজেও "আমার বিলিং লগইন" পেজে দিতে পারেন।')),
            TextInput::make('billing_password')->label(__('বিলিং পাসওয়ার্ড'))->password()->revealable()->visibleOn('edit')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(__('বদলাতে না চাইলে খালি রাখুন')),
            \Filament\Forms\Components\CheckboxList::make('permissions')->label(__('কোন কোন মেনু দেখবেন'))->visibleOn('edit')
                ->options(\App\Support\Menu::options())
                ->columns(3)->bulkToggleable()
                ->formatStateUsing(fn ($state, ?Membership $record) => $state ?? \App\Support\Menu::defaults($record?->role ?? 'agent'))
                ->live()
                ->helperText(__('ড্যাশবোর্ড আর "আমার বিলিং লগইন" সবাই দেখেন। সেটিংসের মেনুগুলো (WhatsApp, বিলিং সংযোগ, MikroTik, বট, AI key, টিম, টেকনিশিয়ান) শুধু Admin হলে কাজ করে, Agent-এর জন্য টিক দিলেও খুলবে না।')),
            Select::make('bill_view_employees')->label(__('বিল কালেকশনে কার কার নেওয়া বিল দেখবেন'))->visibleOn('edit')->multiple()
                ->visible(fn ($get) => $get('role') === 'agent' && in_array('bills', (array) $get('permissions'), true))
                ->options(fn () => \App\Models\BillingDailyBill::collectors(Filament::getTenant()->getKey()))
                ->formatStateUsing(fn ($state) => array_map('strval', (array) ($state ?? [])))
                ->dehydrateStateUsing(fn ($state) => array_values(array_map('intval', (array) ($state ?? []))) ?: null)
                ->helperText(__('বিলিং-এর Received By (যিনি টাকা নিয়েছেন)। খালি রাখলে শুধু নিজের এন্ট্রি দেওয়া বিল দেখবেন (ওপরের বিলিং ইউজারনেম মিলিয়ে)। Owner/Admin সবার বিল দেখেন।')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label(__('নাম'))->searchable(),
                TextColumn::make('user.email')->label(__('ইমেইল'))->searchable(),
                TextColumn::make('role')->label(__('রোল'))->badge()
                    ->formatStateUsing(fn ($state) => Membership::ROLES[$state] ?? $state),
                TextColumn::make('menus')->label(__('মেনু'))
                    ->state(fn (Membership $r) => $r->role === 'owner' ? __('সব')
                        : count($r->permissions ?? \App\Support\Menu::defaults($r->role)).'/'.count(\App\Support\Menu::ITEMS).($r->permissions === null ? __(' (ডিফল্ট)') : ''))
                    ->tooltip(fn (Membership $r) => collect($r->role === 'owner' ? array_keys(\App\Support\Menu::ITEMS) : ($r->permissions ?? \App\Support\Menu::defaults($r->role)))
                        ->map(fn ($k) => \App\Support\Menu::ITEMS[$k][0] ?? $k)->join(', ')),
                TextColumn::make('billing')->label(__('বিলিং লগইন'))->badge()
                    ->state(fn (Membership $r) => $r->hasBillingLogin() ? ($r->billing_check_ok === false ? __('ভুল') : $r->billing_username) : __('দেওয়া নেই'))
                    ->color(fn (Membership $r) => $r->hasBillingLogin() ? ($r->billing_check_ok === false ? 'danger' : 'success') : 'gray'),
                TextColumn::make('created_at')->label(__('যোগ হয়েছে'))->date(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('সদস্য যোগ করুন'))
                    ->using(fn (array $data) => self::addMember($data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Membership $record) => $record->role !== 'owner'
                    || auth()->user()->roleIn(Filament::getTenant()) === 'owner'),
                DeleteAction::make()->label(__('সরান'))
                    ->visible(fn (Membership $record) => $record->role !== 'owner' && $record->user_id !== auth()->id()),
            ]);
    }

    public static function addMember(array $data): Membership
    {
        $company = Filament::getTenant();
        $left = $company->seatsLeft();
        if ($left !== null && $left <= 0) {
            Notification::make()->danger()->title(__('প্যাকেজের ইউজার সীমা শেষ'))
                ->body(__('আরও সদস্য যোগ করতে প্যাকেজ আপগ্রেড করুন।'))->send();
            throw new Halt;
        }
        if (! array_key_exists($data['role'], self::roleOptions())) {
            throw new Halt;
        }
        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            if (blank($data['password'] ?? null)) {
                Notification::make()->danger()->title(__('নতুন ইউজারের জন্য পাসওয়ার্ড দিন'))->send();
                throw new Halt;
            }
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        }
        if ($company->memberships()->where('user_id', $user->id)->exists()) {
            Notification::make()->warning()->title(__('এই ইউজার আগেই টিমে আছে'))->send();
            throw new Halt;
        }

        return $company->memberships()->create(['user_id' => $user->id, 'role' => $data['role']]);
    }

    /** Explicit company scope in addition to Filament tenancy: a record of another company is never listed or editable. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMemberships::route('/')];
    }
}
