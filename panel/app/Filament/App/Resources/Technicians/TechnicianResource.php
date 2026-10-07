<?php

namespace App\Filament\App\Resources\Technicians;

use App\Filament\App\Resources\Technicians\Pages\ManageTechnicians;
use App\Models\Technician;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Field technicians: messages from these WhatsApp numbers get the staff assistant, not the customer bot. */
class TechnicianResource extends Resource
{
    protected static ?string $model = Technician::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static ?string $navigationLabel = 'টেকনিশিয়ান';

    protected static ?string $modelLabel = 'টেকনিশিয়ান';

    protected static ?string $pluralModelLabel = 'টেকনিশিয়ান';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 45;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('নাম')->required(),
            TextInput::make('wa_number')->label('WhatsApp নম্বর')->required()->tel()
                ->placeholder('01XXXXXXXXX')
                ->formatStateUsing(fn (?string $state) => $state && str_starts_with($state, '880') ? '0'.substr($state, 3) : $state)
                ->dehydrateStateUsing(fn (?string $state) => Technician::normalize((string) $state))
                ->rule('regex:/^(\+?88)?01[3-9]\d{8}$/')
                ->rule(fn (?Technician $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                    $exists = Technician::where('company_id', Filament::getTenant()->getKey())
                        ->where('wa_number', Technician::normalize((string) $value))
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
                    if ($exists) {
                        $fail('এই নম্বর আগেই যোগ করা আছে');
                    }
                })
                ->validationMessages(['regex' => 'বাংলাদেশি মোবাইল নম্বর দিন (01XXXXXXXXX)', 'unique' => 'এই নম্বর আগেই যোগ করা আছে']),
            TextInput::make('note')->label('নোট (ঐচ্ছিক)')->placeholder('যেমন এলাকা: Binodpur'),
            Toggle::make('active')->label('চালু')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('এই নম্বরগুলো থেকে মেসেজ এলে বট কাস্টমার হিসেবে নয়, অফিস সাপোর্ট হিসেবে উত্তর দেবে: যেকোনো কাস্টমারের লাইন, PPPoE ID, ONU, বিল জানাবে আর বললে টিকিট খুলবে।')
            ->columns([
                TextColumn::make('name')->label('নাম')->searchable(),
                TextColumn::make('wa_number')->label('WhatsApp')->formatStateUsing(fn ($state, Technician $r) => $r->displayNumber())->searchable(),
                TextColumn::make('note')->label('নোট')->placeholder('—'),
                IconColumn::make('active')->label('চালু')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('টেকনিশিয়ান যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTechnicians::route('/')];
    }
}
