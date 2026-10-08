<?php

namespace App\Filament\App\Resources\BotFaqs;

use App\Filament\App\Resources\BotFaqs\Pages\ManageBotFaqs;
use App\Models\BotFaq;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The company's FAQ: every active row goes into the bot's instructions (customer bot and technician desk). */
class BotFaqResource extends Resource
{
    protected static ?string $model = BotFaq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $navigationLabel = 'বটের FAQ';

    protected static ?string $modelLabel = 'FAQ';

    protected static ?string $pluralModelLabel = 'বটের FAQ';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('bot') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('question')->label('কাস্টমার যা জিজ্ঞেস করে')->required()->maxLength(500)
                ->placeholder('যেমন: FTP সার্ভারের লিংক কী? / 550 টাকায় কোন প্যাকেজ?')->columnSpanFull(),
            Textarea::make('answer')->label('উত্তর')->required()->rows(5)->columnSpanFull()
                ->helperText('সঠিক তথ্য লিখুন (লিংক, দাম, সময়)। বট এই তথ্য না বদলে নিজের ভাষায় ছোট করে বলবে; প্রশ্নটা অন্যভাবে করলেও একই বিষয় হলে এটাই ধরবে।'),
            TextInput::make('sort')->label('ক্রম')->numeric()->default(0),
            Toggle::make('active')->label('চালু')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('এখানকার চালু প্রশ্ন-উত্তরগুলো বটের নির্দেশনায় যোগ হয় (কাস্টমার বট ও টেকনিশিয়ান দুই জায়গাতেই)। সেভ করার পরের মেসেজ থেকেই কাজ করে। পুরো নির্দেশনা দেখতে: বট সেটিংস → "বট এখন যা নির্দেশনা পায়"।')
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('question')->label('প্রশ্ন')->searchable()->wrap(),
                TextColumn::make('answer')->label('উত্তর')->searchable()->wrap()->limit(160),
                ToggleColumn::make('active')->label('চালু'),
            ])
            ->headerActions([CreateAction::make()->label('FAQ যোগ করুন')
                ->mutateDataUsing(fn (array $data) => [...$data, 'company_id' => Filament::getTenant()->getKey()])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBotFaqs::route('/')];
    }
}
