<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerRemark;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Remarks History. */
class RemarksTable extends HistoryTable
{
    protected static string $section = 'remarks';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerRemark::class)->orderByDesc('created_at'))
            ->heading('মন্তব্য')
            ->description(fn () => $this->fetchedNote())
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading('বিলিংয়ে কোনো মন্তব্য নেই')
            ->columns([
                TextColumn::make('created_at')->label('সময়')->dateTime('d M Y h:i A', self::TZ),
                TextColumn::make('remarks_type')->label('ধরন')->badge()->color('gray'),
                TextColumn::make('comment')->label('মন্তব্য')->wrap()->placeholder('—'),
                TextColumn::make('created_by')->label('করেছেন'),
            ]);
    }
}
