<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Models\WaContact;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/** The latest conversations, live. */
class RecentChats extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'সাম্প্রতিক কনভারসেশন';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => WaContact::query()->where('company_id', Filament::getTenant()->getKey())
                ->with(['lastMessage', 'assignedUser'])->orderByDesc('last_message_at'))
            ->poll('10s')
            ->paginated([8])
            ->defaultPaginationPageOption(8)
            ->columns([
                TextColumn::make('name')->label('কাস্টমার')->placeholder('নাম নেই')
                    ->description(fn (WaContact $r) => $r->displayNumber()),
                TextColumn::make('last')->label('শেষ মেসেজ')->state(function (WaContact $r) {
                    $m = $r->lastMessage;
                    if (! $m) {
                        return '—';
                    }
                    $who = $m->direction === 'in' ? '' : ($m->sender === 'bot' ? 'বট: ' : 'আমরা: ');

                    return $who.Str::limit($m->body ?: "[{$m->type}]", 70);
                }),
                TextColumn::make('waiting')->label('অবস্থা')->badge()
                    ->state(fn (WaContact $r) => $r->lastMessage?->direction === 'in' ? 'উত্তরের অপেক্ষায়' : 'উত্তর গেছে')
                    ->color(fn (string $state) => $state === 'উত্তর গেছে' ? 'success' : 'warning'),
                TextColumn::make('last_message_at')->label('সময়')->since(),
            ])
            ->recordUrl(fn (WaContact $r) => ConversationResource::getUrl('view', ['record' => $r]));
    }
}
