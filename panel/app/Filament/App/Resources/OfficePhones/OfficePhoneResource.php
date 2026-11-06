<?php

namespace App\Filament\App\Resources\OfficePhones;

use App\Filament\App\Resources\OfficePhones\Pages\ManageOfficePhones;
use App\Models\OfficePhone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Unique;

/**
 * Office IP phones on our own SIP server: the phone registers here (ph{id}), our Asterisk registers its PBX extension
 * in its place, so every call is recorded and shows in the call log. Owner/Admin only.
 */
class OfficePhoneResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = OfficePhone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    public static function getNavigationLabel(): string
    {
        return __('অফিস ফোন');
    }

    public static function getModelLabel(): string
    {
        return __('অফিস ফোন');
    }

    public static function getPluralModelLabel(): string
    {
        return __('অফিস ফোন');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'ফোন ও কল';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('office_phones') && (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    /** "new-password" is the value Chrome respects; the PBX password must not be autofilled with the Desk login */
    private const NO_AUTOFILL = ['autocomplete' => 'new-password', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label(__('নাম'))->placeholder(__('যেমন সাপোর্ট সেন্টার'))->maxLength(100),
            TextInput::make('pbx_username')->label(__('PBX এক্সটেনশন'))->required()->maxLength(80)
                ->rule('regex:/^[A-Za-z0-9._\-+]+$/')
                ->unique(table: OfficePhone::class, column: 'pbx_username', ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', Filament::getTenant()->getKey()))
                ->helperText(__('pbx.bd-তে এই ফোনের এক্সটেনশন নম্বর, যেমন 103')),
            TextInput::make('pbx_password')->label(__('PBX পাসওয়ার্ড'))->password()->revealable()->maxLength(120)
                ->extraInputAttributes(self::NO_AUTOFILL)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->rule('regex:/^[^;<>\s"]+$/')
                ->validationMessages(['regex' => __('পাসওয়ার্ডে ; < > " বা স্পেস থাকলে চলবে না; PBX-এ পাসওয়ার্ড বদলে নিন')])
                ->helperText(fn (string $operation) => $operation === 'edit' ? __('বদলাতে না চাইলে খালি রাখুন') : __('pbx.bd-তে এই এক্সটেনশনের পাসওয়ার্ড; এনক্রিপ্ট করে রাখা হবে')),
            Toggle::make('enabled')->label(__('চালু'))->default(true)
                ->helperText(__('চালু থাকলে এক মিনিটের মধ্যে আমাদের সার্ভার এই এক্সটেনশন হিসেবে pbx.bd-তে রেজিস্টার হয়')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label(__('নাম'))->placeholder('—'),
                TextColumn::make('pbx_username')->label(__('PBX এক্সটেনশন')),
                TextColumn::make('login')->label(__('ফোনের ইউজারনেম'))->state(fn (OfficePhone $record) => $record->login()),
                TextColumn::make('enabled')->label(__('চালু'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('চালু') : __('বন্ধ'))->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('status')->label(__('অবস্থা'))->wrap()->placeholder(__('এখনো খবর নেই'))
                    ->description(fn (OfficePhone $record) => $record->status_at?->diffForHumans()),
            ])
            ->headerActions([CreateAction::make()->label(__('ফোন যোগ করুন'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])
                ->after(fn () => static::kick())])
            ->recordActions([
                Action::make('setup')->label(__('ফোনের সেটিং'))->icon(Heroicon::OutlinedCog)->color('gray')
                    ->modalHeading(fn (OfficePhone $record) => __('ফোনে এই তথ্য দিন: :n', ['n' => $record->label ?: $record->pbx_username]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ করুন'))
                    ->modalContent(fn (OfficePhone $record) => view('filament.app.office-phone-setup', ['phone' => $record])),
                EditAction::make()->after(fn () => static::kick()),
                DeleteAction::make()->after(fn () => static::kick()),
            ]);
    }

    /** Ask the voice engine to rewrite Asterisk's config now instead of within half a minute. */
    public static function kick(): void
    {
        try {
            Http::timeout(5)->get('http://127.0.0.1:8997/sync');
        } catch (\Throwable) {
            // the engine syncs on its own every 30 s
        }
    }

    /** Explicit company scope in addition to Filament tenancy. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOfficePhones::route('/')];
    }
}
