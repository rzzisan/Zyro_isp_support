<?php

namespace App\Filament\App\Resources\Technicians;

use App\Filament\App\Resources\Technicians\Pages\ManageTechnicians;
use App\Models\Technician;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
        return \App\Support\Menu::can('technicians') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    /** Billing employees [id => name] (the ticket Assign list), plus the saved one if billing can't be reached. */
    public static function employeeOptions(?Technician $record = null): array
    {
        try {
            $options = \App\Services\Engine::ticketOptions((int) Filament::getTenant()->getKey())['employees'] ?? [];
        } catch (\Throwable) {  // billing or the engine unreachable: the form still opens
            $options = [];
        }
        if ($record?->billing_employee_id && ! isset($options[$record->billing_employee_id])) {
            $options[$record->billing_employee_id] = $record->billing_employee_name ?: $record->billing_employee_id;
        }

        return $options;
    }

    /** Keeps the employee's name next to the id, so the list shows it without asking billing. */
    public static function withEmployeeName(array $data, ?Technician $record = null): array
    {
        $id = $data['billing_employee_id'] ?? null;

        return [...$data, 'billing_employee_name' => $id ? (static::employeeOptions($record)[$id] ?? null) : null];
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
            Select::make('billing_employee_id')->label('বিলিংয়ে কোন কর্মী')->searchable()
                ->options(fn (?Technician $record) => static::employeeOptions($record))
                ->helperText('বিলিংয়ে বা ডেস্ক থেকে এই কর্মীকে টিকিট assign করলে টিকিটের তথ্য এই টেকনিশিয়ানের WhatsApp-এ যাবে')
                ->live(),
            Toggle::make('notify_tickets')->label('টিকিট assign হলে WhatsApp-এ জানাবে')->default(true)
                ->visible(fn ($get) => filled($get('billing_employee_id'))),
            Toggle::make('can_switch_lines')->label('এর কথায় বট লাইন চালু/বন্ধ করতে পারবে')->default(false)
                ->helperText('বন্ধ থাকলে লাইন চালু বা বন্ধ করতে বললে বট না বলবে, তবে অনুরোধটা রেকর্ডে থাকবে'),
            Toggle::make('active')->label('চালু')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('এই নম্বরগুলো থেকে মেসেজ এলে বট কাস্টমার হিসেবে নয়, অফিস সাপোর্ট হিসেবে উত্তর দেবে: যেকোনো কাস্টমারের লাইন, PPPoE ID, ONU, বিল জানাবে আর বললে টিকিট খুলবে। বিলিং কর্মী যুক্ত থাকলে সেই কর্মীকে টিকিট assign হলে টিকিটের তথ্য টেকনিশিয়ানের WhatsApp-এ যায়।')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lastTicketNotification'))
            ->columns([
                TextColumn::make('name')->label('নাম')->searchable(),
                TextColumn::make('wa_number')->label('WhatsApp')->formatStateUsing(fn ($state, Technician $r) => $r->displayNumber())->searchable(),
                TextColumn::make('note')->label('নোট')->placeholder('—'),
                TextColumn::make('billing_employee_name')->label('বিলিং কর্মী')->placeholder('—')
                    ->description(fn (Technician $r) => $r->billing_employee_id && ! $r->notify_tickets ? 'টিকিট মেসেজ বন্ধ' : null),
                TextColumn::make('lastTicketNotification.complain_id')->label('শেষ টিকিট মেসেজ')->placeholder('—')
                    ->formatStateUsing(fn ($state, Technician $r) => '#'.$state.' · '.match ($r->lastTicketNotification->status) {
                        'sent' => 'পাঠানো হয়েছে', 'failed' => 'যায়নি', 'skipped' => 'পাঠানো হয়নি', default => 'পাঠানো হচ্ছে',
                    })
                    ->description(fn (Technician $r) => $r->lastTicketNotification?->created_at?->timezone('Asia/Dhaka')->format('d M, h:i A'))
                    ->tooltip(fn (Technician $r) => $r->lastTicketNotification?->error)
                    ->color(fn (Technician $r) => match ($r->lastTicketNotification?->status) {
                        'sent' => 'success', 'failed', 'skipped' => 'danger', default => null,
                    }),
                IconColumn::make('can_switch_lines')->label('লাইন চালু/বন্ধ')->boolean(),
                IconColumn::make('active')->label('চালু')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('টেকনিশিয়ান যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...static::withEmployeeName($data), 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([EditAction::make()->mutateDataUsing(fn (array $data, Technician $record) => static::withEmployeeName($data, $record)),
                DeleteAction::make()]);
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
