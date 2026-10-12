<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerLineLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Customer Enable/Disable Log (billing logs the desk's and the bot's enables too, under their billing user). */
class LineLogsTable extends HistoryTable
{
    protected static string $section = 'line';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerLineLog::class)->orderByDesc('changed_at')->orderByDesc('id'))
            ->heading('লাইন চালু/বন্ধ')
            ->description(fn () => $this->fetchedNote())
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading('কোনো রেকর্ড নেই')
            ->columns([
                TextColumn::make('changed_at')->label('সময়')->dateTime('d M Y h:i A', self::TZ),
                TextColumn::make('action')->label('কাজ')->badge()
                    ->formatStateUsing(fn ($state) => ['enable' => 'চালু', 'disable' => 'বন্ধ'][$state] ?? $state)
                    ->color(fn ($state) => $state === 'enable' ? 'success' : 'danger'),
                TextColumn::make('remarks')->label('কারণ')->wrap()->placeholder('—'),
                TextColumn::make('changed_by')->label('করেছেন'),
            ]);
    }
}
