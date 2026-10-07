<?php

namespace App\Filament\App\Resources\Conversations;

use App\Filament\App\Resources\Conversations\Pages\ListConversations;
use App\Filament\App\Resources\Conversations\Pages\ViewConversation;
use App\Models\WaContact;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** The inbox: every customer number that wrote to the company. All members (agents too) work here. */
class ConversationResource extends Resource
{
    protected static ?string $model = WaContact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'ইনবক্স';

    protected static ?string $modelLabel = 'কনভারসেশন';

    protected static ?string $pluralModelLabel = 'কনভারসেশন';

    protected static ?string $slug = 'inbox';

    protected static ?int $navigationSort = -10;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('inbox');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->defaultSort('last_message_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lastMessage', 'assignedUser']))
            ->columns([
                TextColumn::make('name')->label('কাস্টমার')->placeholder('নাম নেই')
                    ->description(fn (WaContact $r) => $r->displayNumber())
                    ->searchable(['name', 'wa_number', 'customer_id']),
                TextColumn::make('customer_id')->label('ID')->placeholder('অচেনা'),
                TextColumn::make('last')->label('শেষ মেসেজ')
                    ->state(function (WaContact $r) {
                        $m = $r->lastMessage;
                        if (! $m) {
                            return '—';
                        }
                        $who = $m->direction === 'in' ? '' : ($m->sender === 'bot' ? 'বট: ' : 'আমরা: ');

                        return $who.Str::limit($m->body ?: "[{$m->type}]", 60);
                    }),
                TextColumn::make('last_message_at')->label('সময়')->since()->sortable(),
                TextColumn::make('assignedUser.name')->label('দায়িত্বে')->placeholder('কেউ না'),
                TextColumn::make('bot')->label('বট')->badge()
                    ->state(fn (WaContact $r) => $r->isBotPaused() ? 'থামানো' : 'চালু')
                    ->color(fn (string $state) => $state === 'চালু' ? 'success' : 'warning'),
            ])
            ->filters([
                Filter::make('mine')->label('শুধু আমার')
                    ->query(fn (Builder $query) => $query->where('assigned_user_id', auth()->id())),
                Filter::make('unassigned')->label('কারো দায়িত্বে নেই')
                    ->query(fn (Builder $query) => $query->whereNull('assigned_user_id')),
                TernaryFilter::make('paused')->label('বট')
                    ->trueLabel('থামানো')->falseLabel('চালু')
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn ($w) => $w->where('bot_paused', true)->orWhere('bot_paused_until', '>', now())),
                        false: fn (Builder $query) => $query->where('bot_paused', false)->where(fn ($w) => $w->whereNull('bot_paused_until')->orWhere('bot_paused_until', '<=', now())),
                    ),
            ])
            ->recordUrl(fn (WaContact $r) => static::getUrl('view', ['record' => $r]));
    }

    /** Explicit company scope in addition to Filament tenancy. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
