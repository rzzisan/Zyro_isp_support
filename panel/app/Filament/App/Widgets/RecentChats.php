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

    protected function getTableHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return __('সাম্প্রতিক কনভারসেশন');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => WaContact::query()->where('company_id', Filament::getTenant()->getKey())
                ->with(['lastMessage', 'assignedUser'])->orderByDesc('last_message_at'))
            ->poll('10s')
            ->paginated([8])
            ->defaultPaginationPageOption(8)
            ->columns([
                TextColumn::make('name')->label(__('কাস্টমার'))->placeholder(__('নাম নেই'))
                    ->description(fn (WaContact $r) => $r->displayNumber()),
                TextColumn::make('last')->label(__('শেষ মেসেজ'))->state(function (WaContact $r) {
                    $m = $r->lastMessage;
                    if (! $m) {
                        return '—';
                    }
                    $who = $m->direction === 'in' ? '' : ($m->sender === 'bot' ? __('বট: ') : __('আমরা: '));

                    return $who.Str::limit($m->body ?: "[{$m->type}]", 70);
                }),
                TextColumn::make('waiting')->label(__('অবস্থা'))->badge()
                    ->state(fn (WaContact $r) => $r->lastMessage?->direction === 'in' ? __('উত্তরের অপেক্ষায়') : __('উত্তর গেছে'))
                    ->color(fn (string $state) => $state === __('উত্তর গেছে') ? 'success' : 'warning'),
                TextColumn::make('last_message_at')->label(__('সময়'))->since(),
            ])
            ->recordUrl(fn (WaContact $r) => ConversationResource::getUrl('view', ['record' => $r]));
    }
}
