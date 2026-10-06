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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'টিম';

    protected static ?string $modelLabel = 'সদস্য';

    protected static ?string $pluralModelLabel = 'টিম';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
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
            TextInput::make('name')->label('নাম')->required()->visibleOn('create'),
            TextInput::make('email')->label('ইমেইল')->email()->required()->visibleOn('create')
                ->helperText('এই ইমেইলে আগে থেকে অ্যাকাউন্ট থাকলে সেটাই যুক্ত হবে'),
            TextInput::make('password')->label('পাসওয়ার্ড')->password()->revealable()->minLength(10)->visibleOn('create')
                ->helperText('নতুন ইউজার হলে লাগবে'),
            Select::make('role')->label('রোল')->options(fn () => self::roleOptions())->required()->default('agent'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('নাম')->searchable(),
                TextColumn::make('user.email')->label('ইমেইল')->searchable(),
                TextColumn::make('role')->label('রোল')->badge()
                    ->formatStateUsing(fn ($state) => Membership::ROLES[$state] ?? $state),
                TextColumn::make('created_at')->label('যোগ হয়েছে')->date(),
            ])
            ->headerActions([
                CreateAction::make()->label('সদস্য যোগ করুন')
                    ->using(fn (array $data) => self::addMember($data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Membership $record) => $record->role !== 'owner'
                    || auth()->user()->roleIn(Filament::getTenant()) === 'owner'),
                DeleteAction::make()->label('সরান')
                    ->visible(fn (Membership $record) => $record->role !== 'owner' && $record->user_id !== auth()->id()),
            ]);
    }

    public static function addMember(array $data): Membership
    {
        $company = Filament::getTenant();
        $left = $company->seatsLeft();
        if ($left !== null && $left <= 0) {
            Notification::make()->danger()->title('প্যাকেজের ইউজার সীমা শেষ')
                ->body('আরও সদস্য যোগ করতে প্যাকেজ আপগ্রেড করুন।')->send();
            throw new Halt;
        }
        if (! array_key_exists($data['role'], self::roleOptions())) {
            throw new Halt;
        }
        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            if (blank($data['password'] ?? null)) {
                Notification::make()->danger()->title('নতুন ইউজারের জন্য পাসওয়ার্ড দিন')->send();
                throw new Halt;
            }
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        }
        if ($company->memberships()->where('user_id', $user->id)->exists()) {
            Notification::make()->warning()->title('এই ইউজার আগেই টিমে আছে')->send();
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
