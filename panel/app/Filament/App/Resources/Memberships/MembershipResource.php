<?php

namespace App\Filament\App\Resources\Memberships;

use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Models\BillingDailyBill;
use App\Models\Membership;
use App\Models\User;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Team: who works in this company and with which role. Owner/Admin only; owners can't be removed here. */
class MembershipResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

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
        return Menu::can('team') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
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

    /** Billing logins of members are set by the owner only. */
    public static function isOwner(): bool
    {
        return auth()->user()?->roleIn(Filament::getTenant()) === 'owner';
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
            TextInput::make('billing_username')->label(__('বিলিং সফটওয়্যারের ইউজারনেম'))->visibleOn('edit')
                ->visible(fn (string $operation) => $operation === 'edit' && self::isOwner())
                ->helperText(__('শুধু Owner দিতে পারেন। এই লগইন ছাড়া সদস্য বিল নিতে বা লাইন চালু/বন্ধ করতে পারবেন না; টিকিট এই লগইনে তাঁর নামে হবে।')),
            TextInput::make('billing_password')->label(__('বিলিং পাসওয়ার্ড'))->password()->revealable()->visibleOn('edit')
                ->visible(fn (string $operation) => $operation === 'edit' && self::isOwner())
                ->dehydrated(fn ($state) => filled($state) && self::isOwner())
                ->helperText(__('বদলাতে না চাইলে খালি রাখুন')),
            Toggle::make('field_technician')->label(__('ফিল্ড টেকনিশিয়ান'))->visibleOn('edit')->live()
                ->visible(fn (string $operation) => $operation === 'edit' && self::isOwner())
                ->dehydrated(fn () => self::isOwner())
                ->helperText(__('টিক দিলে অ্যাপে টেকনিশিয়ানের অংশ পাবেন: "আমার কাজ", লোকেশন, আর শুধু চলমান কাজের কাস্টমারের সাথে চ্যাট। Agent হলে অ্যাপ "আমার কাজ" দিয়ে খুলবে।')),
            Select::make('billing_employee_id')->label(__('বিলিংয়ে কোন কর্মী (ফিল্ড কাজ)'))->visibleOn('edit')->searchable()
                ->visible(fn (string $operation, $get) => $operation === 'edit' && $get('field_technician'))
                ->options(fn () => \App\Filament\App\TicketActions::options('employees'))
                ->formatStateUsing(fn ($state) => filled($state) ? (string) $state : null)
                ->helperText(__('টিকিটে এই কর্মীর নাম থাকলে সেগুলো অ্যাপের "আমার কাজ"-এ দেখাবে।')),
            Toggle::make('wa_signature')->label(__('WhatsApp মেসেজের নিচে নাম দেখাবে'))->default(true)
                ->visible(fn (string $operation) => $operation === 'edit' && self::isOwner())
                ->dehydrated(fn () => self::isOwner())
                ->helperText(__('চালু থাকলে এই সদস্যের পাঠানো প্রতিটা WhatsApp উত্তরের নিচে "- নাম" যাবে। শুধু Owner বদলাতে পারেন।')),
            Select::make('groups')->label(__('পারমিশন গ্রুপ'))->visibleOn('edit')->multiple()->preload()->live()
                ->visible(fn ($get) => $get('role') !== 'owner')
                ->relationship('groups', 'name', fn (Builder $query) => $query->where('permission_groups.company_id', Filament::getTenant()->getKey()))
                ->helperText(__('গ্রুপের সব মেনু পাবেন, সাথে নিচে নিজের টিক দেওয়া মেনুও। গ্রুপ বানাতে বা বদলাতে "পারমিশন গ্রুপ" পেজ।')),
            CheckboxList::make('permissions')->label(__('কোন কোন মেনু দেখবেন'))->visibleOn('edit')
                ->options(Menu::options())
                ->columns(3)->bulkToggleable()
                // in a group and no own list yet: the own list starts empty (the groups give the menus), else the role defaults
                ->formatStateUsing(fn ($state, ?Membership $record) => $state !== null ? Menu::selectable($state) : ($record?->groups()->exists() ? [] : Menu::defaults($record?->role ?? 'agent')))
                ->dehydrateStateUsing(fn ($state, $get) => ! $state && filled($get('groups')) ? null : array_values((array) $state))
                ->live()
                ->helperText(__('ড্যাশবোর্ড আর "আমার বিলিং লগইন" সবাই দেখেন। সেটিংসের মেনুগুলো (WhatsApp, বিলিং সংযোগ, MikroTik, বট, AI key, টিম, টেকনিশিয়ান) শুধু Admin হলে কাজ করে, Agent-এর জন্য টিক দিলেও খুলবে না।')),
            Select::make('bill_view_employees')->label(__('বিল কালেকশনে কার কার নেওয়া বিল দেখবেন'))->visibleOn('edit')->multiple()
                ->visible(fn ($get) => $get('role') === 'agent' && (in_array('bills', (array) $get('permissions'), true)
                    || \App\Models\PermissionGroup::whereIn('id', (array) $get('groups'))->pluck('permissions')->flatten()->contains('bills')))
                ->options(fn () => BillingDailyBill::collectors(Filament::getTenant()->getKey()))
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
                TextColumn::make('groups.name')->label(__('পারমিশন গ্রুপ'))->badge()->placeholder('-'),
                \Filament\Tables\Columns\IconColumn::make('field_technician')->label(__('টেকনিশিয়ান'))->boolean()
                    ->trueIcon('heroicon-o-wrench-screwdriver')->falseIcon('heroicon-o-minus')->trueColor('success')->falseColor('gray'),
                TextColumn::make('menus')->label(__('মেনু'))
                    ->state(fn (Membership $r) => $r->role === 'owner' ? __('সব')
                        : count(Menu::keys($r)).'/'.count(Menu::ITEMS).($r->permissions === null && $r->groups->isEmpty() ? __(' (ডিফল্ট)') : ''))
                    ->tooltip(fn (Membership $r) => collect($r->role === 'owner' ? array_keys(Menu::ITEMS) : Menu::keys($r))
                        ->map(fn ($k) => Menu::ITEMS[$k][0] ?? $k)->join(', ')),
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
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0)->with('groups');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMemberships::route('/')];
    }
}
