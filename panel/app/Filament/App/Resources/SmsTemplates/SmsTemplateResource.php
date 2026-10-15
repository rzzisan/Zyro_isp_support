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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
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

    protected static ?string $navigationLabel = 'SMS টেমপ্লেট';

    protected static ?string $modelLabel = 'টেমপ্লেট';

    protected static ?string $pluralModelLabel = 'SMS টেমপ্লেট';

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
            Textarea::make('body')->label('লেখা')->required()->rows(5)->maxLength(1000)->columnSpanFull()
                ->helperText('কাস্টমারের তথ্য বসাতে: '.collect(Sms::PLACEHOLDERS)->map(fn ($p, $k) => '{'.$k.'} '.$p[0])->implode(', ')
                    .'। যেমন: "প্রিয় {name}, আপনার বকেয়া {due} টাকা।"'),
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
