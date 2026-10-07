<?php

namespace App\Filament\App\Resources\AiKeys;

use App\Filament\App\Resources\AiKeys\Pages\ManageAiKeys;
use App\Models\AiKey;
use App\Services\AiKeyTester;
use BackedEnum;
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
use Throwable;

/** The company's own AI keys (each company pays its provider). Owner/Admin only. */
class AiKeyResource extends Resource
{
    protected static ?string $model = AiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'AI key';

    protected static ?string $modelLabel = 'AI key';

    protected static ?string $pluralModelLabel = 'AI key';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('ai_keys') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider')->label('প্রোভাইডার')->options(AiKey::providerOptions())->required(),
            TextInput::make('label')->label('নাম (ঐচ্ছিক)')->placeholder('যেমন key-1'),
            TextInput::make('api_key')->label('API key')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'বদলাতে না চাইলে খালি রাখুন' : 'এনক্রিপ্ট করে রাখা হবে'),
            TextInput::make('model')->label('এই key-এর মডেল (ঐচ্ছিক)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('প্রোভাইডার')
                    ->formatStateUsing(fn ($state) => AiKey::PROVIDERS[$state][0] ?? $state),
                TextColumn::make('label')->label('নাম')->placeholder('—'),
                TextColumn::make('masked')->label('Key')->state(fn (AiKey $record) => $record->maskedKey()),
                TextColumn::make('model')->label('মডেল')->placeholder('সাজেস্টেড'),
                TextColumn::make('last_check_ok')->label('শেষ পরীক্ষা')
                    ->state(fn (AiKey $record) => $record->last_checked_at === null ? '—' : ($record->last_check_ok ? '✅ ঠিক আছে' : '❌ ব্যর্থ')),
            ])
            ->headerActions([CreateAction::make()->label('key যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([
                Action::make('test')->label('পরীক্ষা')->icon(Heroicon::OutlinedSignal)
                    ->action(function (AiKey $record) {
                        try {
                            $n = AiKeyTester::check($record);
                            $record->forceFill(['last_checked_at' => now(), 'last_check_ok' => true])->save();
                            Notification::make()->success()->title("key ঠিক আছে — {$n}টা মডেল পাওয়া গেছে")->send();
                        } catch (Throwable $e) {
                            $record->forceFill(['last_checked_at' => now(), 'last_check_ok' => false])->save();
                            Notification::make()->danger()->title('key কাজ করছে না')->body($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** Explicit company scope in addition to Filament tenancy: a record of another company is never listed or editable. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiKeys::route('/')];
    }
}
