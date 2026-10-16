<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerInvoice;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Product & Service Sales Invoices. */
class InvoicesTable extends HistoryTable
{
    protected static string $section = 'invoices';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerInvoice::class)->orderByDesc('invoice_id'))
            ->heading(__('ইনভয়েস'))
            ->description(fn () => $this->fetchedNote())
            ->paginated([10, 25])->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('কোনো ইনভয়েস নেই'))
            ->columns([
                TextColumn::make('invoice_no')->label(__('নং')),
                TextColumn::make('invoice_type')->label(__('ধরন'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => ['ServiceInvoice' => __('সার্ভিস'), 'ProductInvoice' => __('প্রোডাক্ট')][$state] ?? $state),
                TextColumn::make('total')->label(__('মোট'))->numeric(2),
                TextColumn::make('paid')->label(__('জমা'))->numeric(2),
                TextColumn::make('discount')->label(__('ডিসকাউন্ট'))->numeric(2),
                TextColumn::make('due')->label(__('বকেয়া'))->numeric(2)->color(fn ($state) => (float) $state > 0 ? 'danger' : null),
                TextColumn::make('due_date')->label(__('শেষ তারিখ'))->date('d M Y')->placeholder('—'),
                TextColumn::make('special_note')->label(__('নোট'))->limit(40)->placeholder('—'),
            ]);
    }
}
