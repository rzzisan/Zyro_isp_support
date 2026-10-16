<?php

namespace App\Filament\App\Resources\SmsTemplates;

use App\Filament\App\Resources\SmsTemplates\Pages\ManageSmsTemplates;
use App\Models\SmsTemplate;
use App\Support\Menu;
use App\Support\Sms;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Ready SMS texts for the SMS form (Desk's own; billing's templates are not used). */
class SmsTemplateResource extends Resource
{
    protected static ?string $model = SmsTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    public static function getNavigationLabel(): string
    {
        return __('SMS টেমপ্লেট');
    }

    public static function getModelLabel(): string
    {
        return __('টেমপ্লেট');
    }

    public static function getPluralModelLabel(): string
    {
        return __('SMS টেমপ্লেট');
    }

    protected static ?string $slug = 'sms-templates';

    protected static string|\UnitEnum|null $navigationGroup = 'SMS';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Menu::can('sms');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('নাম')->required()->maxLength(100)->placeholder('যেমন: বকেয়া মনে করানো'),
            Select::make('type')->label('ধরন')->options(SmsTemplate::TYPES)->default('general')->required()
                ->selectablePlaceholder(false)->live()
                ->helperText(fn ($get) => match ($get('type')) {
                    'bill_paid' => '"বিল নিন" দিয়ে বিল নেওয়ার পর কাস্টমারকে এই লেখা যায় (সেটিংস → SMS গেটওয়ে-তে বাছা টেমপ্লেট); {paid} {discount} {due} ওই বিল থেকে বসে',
                    'ticket_open' => 'Desk থেকে টিকিট খোলার পর কাস্টমারকে যায়; {ticket_no} {problem} {technician} ওই টিকিট থেকে বসে',
                    'ticket_solved' => 'Desk থেকে টিকিট Solve করার পর কাস্টমারকে যায়; {ticket_no} {problem} ওই টিকিট থেকে বসে',
                    'bill_reminder' => 'মেয়াদ শেষের কয়েক দিন আগে নিজে থেকে যায় (সেটিংস → SMS গেটওয়ে → বিল রিমাইন্ডার); {expire_date} {days_left} বসে',
                    default => 'হাতে SMS পাঠানোর সময় বাছা যায়',
                }),
            View::make('filament.app.sms.placeholders')->columnSpanFull()
                ->viewData(fn ($get) => ['items' => Sms::placeholders((string) ($get('type') ?: 'general'))]),
            Textarea::make('body')->label('লেখা')->required()->rows(5)->maxLength(1000)->columnSpanFull()
                ->extraInputAttributes(['data-sms-body' => 'true'])->live(debounce: 400)
                ->helperText(function ($get) {
                    $p = Sms::parts((string) $get('body'));

                    return $p['chars'].' অক্ষর · '.$p['parts'].' SMS ('.($p['unicode'] ? 'বাংলা/ইউনিকোড: ৭০, বড় হলে ৬৭ করে' : 'ইংরেজি: ১৬০, বড় হলে ১৫৩ করে')
                        .')'.(Sms::usesPlaceholders((string) $get('body')) ? ' · তথ্য বসার আগে' : '');
                }),
            TextInput::make('sort')->label('ক্রম')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->emptyStateHeading('কোনো টেমপ্লেট নেই')
            ->columns([
                TextColumn::make('name')->label('নাম')->searchable(),
                TextColumn::make('type')->label('ধরন')->badge()
                    ->formatStateUsing(fn (string $state) => SmsTemplate::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'general' ? 'gray' : 'success'),
                TextColumn::make('body')->label('লেখা')->wrap()->limit(200)->searchable(),
                TextColumn::make('length')->label('দৈর্ঘ্য')
                    ->state(fn (SmsTemplate $r) => ($p = Sms::parts($r->body))['chars'].' অক্ষর · '.$p['parts'].' SMS'
                        .(Sms::usesPlaceholders($r->body) ? ' (তথ্য বসার আগে)' : '')),
            ])
            ->headerActions([CreateAction::make()->label('টেমপ্লেট যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSmsTemplates::route('/')];
    }
}
