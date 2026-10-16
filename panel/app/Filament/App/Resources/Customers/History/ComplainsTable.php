<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Filament\App\TicketActions;
use App\Models\BillingTicket;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Complain History: the customer's tickets (ticket sync + older ones from billing's complain history). */
class ComplainsTable extends HistoryTable
{
    protected static string $section = 'complains';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingTicket::class)->orderByDesc('opened_at'))
            ->heading(__('অভিযোগ'))
            ->description(fn () => $this->fetchedNote())
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('কোনো টিকিট নেই'))
            ->columns([
                TextColumn::make('complain_id')->label(__('টিকিট'))->prefix('#'),
                TextColumn::make('category')->label(__('সমস্যা')),
                TextColumn::make('priority')->label('Priority')->badge()
                    ->color(fn ($state) => ['high' => 'danger', 'medium' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('state')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => BillingTicket::STATES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'solved' ? 'success' : ($state === 'processing' ? 'info' : 'warning')),
                TextColumn::make('opened_at')->label(__('খোলা'))->dateTime('d M Y h:i A', self::TZ),
                TextColumn::make('created_by')->label(__('খুলেছেন')),
                TextColumn::make('solved_by')->label(__('সমাধান করেছেন'))->placeholder('—'),
                TextColumn::make('solved_at')->label(__('সমাধান'))->dateTime('d M Y h:i A', self::TZ)->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()->label(__('বিস্তারিত'))->modalWidth('4xl')
                    ->modalHeading(fn (BillingTicket $record) => __('টিকিট #:id', ['id' => $record->complain_id]))
                    ->modalContent(fn (BillingTicket $record) => TicketActions::details($record))
                    ->schema([]),
            ])
            ->recordAction('view');
    }
}
