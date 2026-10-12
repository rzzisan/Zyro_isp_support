<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerBill;
use App\Models\BillingCustomerPayment;
use App\Models\BillingDailyBill;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Received Bill History: billing's approved receipts, with lifetime totals and bills still waiting for approval. */
class PaymentsTable extends HistoryTable
{
    protected static string $section = 'payments';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerPayment::class)->whereNull('removed_at')
                ->orderByDesc('received_on')->orderByDesc('bill_header_id'))
            ->heading('পেমেন্ট')
            ->description(fn () => $this->summary())
            ->paginated([12, 24, 60])->defaultPaginationPageOption(12)
            ->emptyStateHeading('বিলিংয়ে কোনো পেমেন্ট নেই')
            ->columns([
                TextColumn::make('received_on')->label('তারিখ')->date('d M Y')
                    ->description(fn (BillingCustomerPayment $r) => $r->is_canceled ? 'বাতিল' : null),
                TextColumn::make('bill_month')->label('মাস'),
                TextColumn::make('paid')->label('জমা')->numeric(2),
                TextColumn::make('discount')->label('ডিসকাউন্ট')->numeric(2),
                TextColumn::make('method')->label('পদ্ধতি')->badge()
                    ->color(fn (BillingCustomerPayment $r) => $r->is_online ? 'info' : 'gray')
                    ->description(fn (BillingCustomerPayment $r) => $r->is_online ? 'অনলাইন' : null),
                TextColumn::make('received_by')->label('নিয়েছেন')->placeholder('—'),
                TextColumn::make('created_by')->label('এন্ট্রি')
                    ->description(fn (BillingCustomerPayment $r) => $r->approved_by ? 'approve: '.$r->approved_by : null),
                TextColumn::make('money_receipt_no')->label('রিসিট নং')->fontFamily('mono')->size('xs')
                    ->description(fn (BillingCustomerPayment $r) => $this->fromDesk($r) ? 'ডেস্ক থেকে নেওয়া' : null),
                TextColumn::make('remarks')->label('মন্তব্য')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('receipt')->label('রিসিট')->icon('heroicon-o-printer')->color('gray')->link()
                    ->visible(fn (BillingCustomerPayment $r) => (bool) $r->daily_bill_id)
                    ->url(fn (BillingCustomerPayment $r) => url('/print/'.Filament::getTenant()?->slug.'/bill/'.$r->daily_bill_id), true),
            ]);
    }

    private function fromDesk(BillingCustomerPayment $r): bool
    {
        return $r->daily_bill_id && DB::table('bill_collections')->where('company_id', $r->company_id)
            ->where('daily_bill_id', $r->daily_bill_id)->where('result', 'saved')->exists();
    }

    private function summary(): string
    {
        $paid = $this->rows(BillingCustomerPayment::class)->whereNull('removed_at')->where('is_canceled', false);
        $billed = (float) $this->rows(BillingCustomerBill::class)->whereNull('removed_at')->sum('bill_amount');
        $parts = [
            'মোট বিল '.number_format($billed, 2),
            'মোট জমা '.number_format((float) (clone $paid)->sum('paid'), 2),
            'মোট ডিসকাউন্ট '.number_format((float) (clone $paid)->sum('discount'), 2),
        ];
        $pending = BillingDailyBill::where('company_id', $this->record?->company_id ?? 0)
            ->where('customer_header_id', $this->record?->header_id ?? 0)
            ->where('is_approved', false)->where('is_deleted', false)->get();
        if ($pending->isNotEmpty()) {
            $parts[] = 'approve-এর অপেক্ষায় '.$pending->map(fn ($d) => number_format((float) $d->receive_amount, 2)
                .' ('.$d->collected_at->timezone(self::TZ)->format('d M').')')->join(', ');
        }

        return implode(' · ', $parts).' — '.$this->fetchedNote();
    }
}
