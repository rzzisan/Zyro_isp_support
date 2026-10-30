<?php

namespace App\Filament\App\Resources\VoiceOptouts;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\VoiceOptouts\Pages\ManageVoiceOptouts;
use App\Models\BillingCustomer;
use App\Models\VoiceOptout;
use App\Support\Menu;
use App\Support\Sms;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Numbers that get no voice reminder calls: who pressed 9 on a call, or staff added. Owner/Admin can take them off. */
class VoiceOptoutResource extends Resource
{
    use QuickAccessible;

    protected static ?string $model = VoiceOptout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $slug = 'voice-optouts';

    protected static string|\UnitEnum|null $navigationGroup = 'ভয়েস রিমাইন্ডার';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('আর কল নয়');
    }

    public static function getModelLabel(): string
    {
        return __('নম্বর');
    }

    public static function getPluralModelLabel(): string
    {
        return __('"আর কল নয়" নম্বর');
    }

    public static function canAccess(): bool
    {
        return Menu::can('voice_reminder');
    }

    private static function company(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number')->label(__('মোবাইল নম্বর'))->required()->tel()->placeholder('01XXXXXXXXX'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('কেউ "আর কল নয়" বলেননি'))
            ->columns([
                TextColumn::make('number')->label(__('নম্বর'))->searchable()
                    ->formatStateUsing(fn ($state) => '0'.substr((string) $state, 3)),
                TextColumn::make('customer')->label(__('কাস্টমার'))
                    ->state(fn (VoiceOptout $r) => BillingCustomer::where('company_id', $r->company_id)
                        ->where(fn ($q) => $q->where('header_id', $r->customer_header_id)->orWhere('mobile_normalized', $r->number))
                        ->get(['name', 'customer_id'])->map(fn ($c) => $c->name.' (ID '.$c->customer_id.')')->take(3)->join(', ')),
                TextColumn::make('source')->label(__('কীভাবে'))->badge()->formatStateUsing(fn ($state) => __(VoiceOptout::SOURCES[$state] ?? $state)),
                TextColumn::make('created_at')->label(__('কবে'))->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M Y, g:i A')),
            ])
            ->headerActions([
                CreateAction::make()->label(__('নম্বর যোগ করুন'))
                    ->mutateDataUsing(function (array $data) {
                        $n = Sms::mobile((string) $data['number']);
                        if (! $n) {
                            throw ValidationException::withMessages(['number' => __('সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)')]);
                        }
                        if (VoiceOptout::where('company_id', static::company())->where('number', $n)->exists()) {
                            throw ValidationException::withMessages(['number' => __('এই নম্বর আগেই তালিকায় আছে')]);
                        }
                        $c = BillingCustomer::where('company_id', static::company())->where('mobile_normalized', $n)->value('header_id');

                        return ['company_id' => static::company(), 'number' => $n, 'customer_header_id' => $c, 'source' => 'staff'];
                    }),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('তালিকা থেকে তুলুন'))->modalHeading(__('আবার রিমাইন্ডার কল যাবে?')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company());
    }

    public static function getPages(): array
    {
        return ['index' => ManageVoiceOptouts::route('/')];
    }
}
