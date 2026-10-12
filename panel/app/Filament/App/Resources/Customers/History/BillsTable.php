<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerBill;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Generated & Updated Bills: every monthly bill billing made for the customer, with its edits. */
class BillsTable extends HistoryTable
{
    protected static string $section = 'bills';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerBill::class)->whereNull('removed_at')
                ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('bill_master_id'))
            ->heading('বিল')
            ->description(fn () => $this->fetchedNote())
            ->paginated([12, 24, 60])->defaultPaginationPageOption(12)
            ->emptyStateHeading('বিলিংয়ে কোনো বিল নেই')
            ->columns([
                TextColumn::make('month_name')->label('মাস'),
                TextColumn::make('created_on')->label('তৈরি')->date('d M Y'),
                TextColumn::make('package')->label('প্যাকেজ'),
                TextColumn::make('speed')->label('প্রোফাইল/স্পিড')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bill_amount')->label('বিল')->numeric(2),
                TextColumn::make('discount')->label('ডিসকাউন্ট')->numeric(2),
                TextColumn::make('paid')->label('জমা')->numeric(2),
                TextColumn::make('due')->label('বকেয়া')->numeric(2)
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : null),
                TextColumn::make('status')->label('অবস্থা')->badge()->color('gray'),
                TextColumn::make('processed_by')->label('তৈরি করেছেন'),
            ])
            ->recordActions([
                Action::make('versions')->label(fn (BillingCustomerBill $r) => 'এডিট '.max(0, count($r->versions ?? []) - 1))
                    ->icon('heroicon-o-clock')->color('gray')->link()
                    ->visible(fn (BillingCustomerBill $r) => count($r->versions ?? []) > 1)
                    ->modalHeading(fn (BillingCustomerBill $r) => "{$r->month_name}: বিলের এডিট-ইতিহাস")
                    ->modalSubmitAction(false)->modalCancelActionLabel('বন্ধ করুন')
                    ->modalContent(fn (BillingCustomerBill $r) => view('filament.app.customers.bill-versions', ['bill' => $r])),
            ]);
    }
}
