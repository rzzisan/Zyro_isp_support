<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerSms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Message History: SMS billing sent the customer, last 12 months (older ones are not kept). */
class SmsTable extends HistoryTable
{
    protected static string $section = 'sms';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerSms::class)->orderByDesc('sent_at')->orderByDesc('sms_log_id'))
            ->heading('SMS (শেষ ১২ মাস)')
            ->description(fn () => $this->fetchedNote())
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading('শেষ ১২ মাসে কোনো SMS নেই')
            ->filters([
                SelectFilter::make('type_name')->label('ধরন')->options(fn () => $this->rows(BillingCustomerSms::class)
                    ->whereNotNull('type_name')->distinct()->orderBy('type_name')->pluck('type_name', 'type_name')->all()),
            ])
            ->columns([
                TextColumn::make('sent_at')->label('সময়')
                    ->formatStateUsing(fn ($state) => $state?->timezone(self::TZ)
                        ->format($state->timezone(self::TZ)->format('H:i') === '00:00' ? 'd M Y' : 'd M Y h:i A')),
                TextColumn::make('type_name')->label('ধরন')->badge()->color('gray'),
                TextColumn::make('text')->label('লেখা')->wrap()->limit(160),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->color(fn ($state) => strtolower((string) $state) === 'success' ? 'success' : 'danger'),
                TextColumn::make('sent_by')->label('পাঠিয়েছেন')->placeholder('সিস্টেম'),
            ]);
    }
}
