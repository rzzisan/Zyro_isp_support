<?php

namespace App\Filament\App\Resources\StaffTeams;

use App\Filament\App\Resources\StaffTeams\Pages\ManageStaffTeams;
use App\Filament\App\TicketActions;
use App\Models\StaffTeam;
use BackedEnum;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Field teams (Rifat + Maruf, ...): members count as field staff; auto-assign gives the whole team, and one ticket never mixes two teams. */
class StaffTeamResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = StaffTeam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationLabel(): string
    {
        return __('ফিল্ড টিম');
    }

    public static function getModelLabel(): string
    {
        return __('টিম');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ফিল্ড টিম');
    }

    protected static ?string $slug = 'staff-teams';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('tickets') && static::manages();
    }

    private static function manages(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    public static function canCreate(): bool
    {
        return static::manages();
    }

    public static function canEdit($record): bool
    {
        return static::manages();
    }

    public static function canDelete($record): bool
    {
        return static::manages();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('টিমের নাম'))->required()->maxLength(100)->placeholder(__('যেমন রিফাত-মারুফ')),
            Select::make('members')->label(__('কর্মী'))->multiple()->searchable()->required()
                ->options(fn () => TicketActions::options('employees'))
                ->helperText(__('বিলিংয়ের কর্মী; টিমে থাকলে তিনি ফিল্ড কর্মী হিসেবে অটো assign পাবেন। নতুন কর্মী না দেখালে উপরের "বিলিং থেকে কর্মী লিস্ট আনুন" চাপুন'))
                ->rule(fn (?StaffTeam $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                    $taken = [];  // employee id => team name (ids are numeric strings: no flatMap, it renumbers keys)
                    foreach (StaffTeam::where('company_id', Filament::getTenant()->getKey())
                        ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))->get() as $t) {
                        foreach ($t->members as $m) {
                            $taken[(string) $m] = $t->name;
                        }
                    }
                    $names = TicketActions::options('employees');
                    foreach ((array) $value as $m) {
                        if (isset($taken[(string) $m])) {
                            $fail(__(':who আগেই ":team" টিমে আছেন', ['who' => $names[$m] ?? $m, 'team' => $taken[(string) $m]]));
                        }
                    }
                })
                ->dehydrateStateUsing(fn ($state) => array_map('strval', array_values((array) $state))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('নতুন টিকিট অটো assign হলে বাছাই করা কর্মীর পুরো টিম পায়। এক টিকিটে একই টিমের কর্মীরা থাকতে পারেন, আলাদা টিমের কাউকে একসাথে দেওয়া যায় না।'))
            ->columns([
                TextColumn::make('name')->label(__('টিম'))->weight('medium')->searchable(),
                TextColumn::make('members')->label(__('কর্মী'))->badge()
                    ->formatStateUsing(fn ($state) => TicketActions::options('employees')[$state] ?? $state),
            ])
            ->headerActions([
                Action::make('syncEmployees')->label(__('বিলিং থেকে কর্মী লিস্ট আনুন'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn () => ! \App\Models\BillingConnection::deskTicketLists((int) Filament::getTenant()->getKey()))
                    ->action(function () {
                        try {
                            Engine::syncBillingConfig((int) Filament::getTenant()->getKey(), 'employees');
                            Notification::make()->success()->title(__('কর্মী লিস্ট বিলিং থেকে আনা হয়েছে'))->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title(__('বিলিং থেকে আনা যায়নি'))->body($e->getMessage())->send();
                        }
                    }),
                CreateAction::make()->label(__('টিম যোগ করুন'))
                    ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaffTeams::route('/')];
    }
}
