<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskCustomer;
use App\Models\DeskPayment;
use App\Support\Ledger;
use App\Support\Menu;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Desk খাতা: পেমেন্ট (billing phase 4b): the Desk's own payments next to the ones copied from billing, with cancel
 * (cash only) and transfer to another ID. Owner and members with a ledger permission.
 */
class BillingLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $slug = 'billing-ledger';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    /** ?customer=0001: one customer's payments (from the customer page). */
    #[Url]
    public ?string $customer = null;

    public function getTitle(): string|Htmlable
    {
        return $this->customer ? __('Desk খাতা: পেমেন্ট (ID :c)', ['c' => $this->customer]) : __('Desk খাতা: পেমেন্ট');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();
        $u = auth()->user();

        return $company && ($u?->roleIn($company) === 'owner' || Menu::allows($u, $company, 'bill_edit')
            || Menu::allows($u, $company, 'payment_delete') || Menu::allows($u, $company, 'payment_transfer'));
    }

    public function getSubheading(): ?string
    {
        $s = BillingSetting::forCompany(Filament::getTenant());
        $mode = $s->ledger_control ?? 'off';

        return __('খাতায় লেখা এখন: :m.', ['m' => __(Ledger::CONTROL[$mode] ?? $mode)])
            .($mode === 'test' ? ' '.__('পরীক্ষার অ্যাকাউন্ট: :c।', ['c' => implode(', ', $s->ledger_test_codes ?? []) ?: '—']) : '')
            .' '.__('কোনো পেমেন্ট মোছা হয় না: বাতিল বা ট্রান্সফার হলে খাতায় উল্টো সারি পড়ে আর বকেয়া সাথে সাথে বদলায়। অনলাইন পেমেন্ট বাতিল করা যায় না, শুধু ট্রান্সফার। বিলিং সফটওয়্যারে কিছু লেখা হয় না।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bills')->label(__('বিল ও সংশোধন'))->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->url(fn () => BillingLedgerBills::getUrl($this->customer ? ['customer' => $this->customer] : [])),
            Action::make('statement')->label(__('হিসাব বিবরণী'))->icon(Heroicon::OutlinedDocumentChartBar)->color('gray')
                ->visible(fn () => filled($this->customer) && DeskCustomer::where('company_id', Filament::getTenant()->getKey())->where('customer_code', $this->customer)->exists())
                ->url(fn () => route('customers.statement', ['company' => Filament::getTenant(), 'code' => $this->customer]), true),
            Action::make('waivers')->label(__('বিল মাফ'))->icon(Heroicon::OutlinedReceiptRefund)->color('gray')
                ->url(fn () => BillingWaivers::getUrl()),
            Action::make('all')->label(__('সব কাস্টমার'))->color('gray')->visible(fn () => filled($this->customer))
                ->url(fn () => static::getUrl()),
        ];
    }

    public static function customerUrl(?DeskCustomer $c): ?string
    {
        $b = $c ? BillingCustomer::where('company_id', $c->company_id)->where('header_id', $c->header_id)->first() : null;

        return $b ? CustomerResource::getUrl('view', ['record' => $b]) : null;
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(DeskPayment::query()->with('customer')->where('company_id', $company->getKey())
                ->when(filled($this->customer), fn ($q) => $q->whereIn('customer_ref', DeskCustomer::where('company_id', $company->getKey())
                    ->where(fn ($w) => $w->where('customer_code', $this->customer)->orWhere('username', $this->customer))->select('id'))))
            ->defaultSort('id', 'desc')
            ->deferFilters(false)
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('paid_on')->label(__('তারিখ'))->date('d M Y')
                    ->description(fn (DeskPayment $r) => $r->received_by),
                TextColumn::make('customer.customer_code')->label('ID')->url(fn (DeskPayment $r) => static::customerUrl($r->customer))
                    ->description(fn (DeskPayment $r) => $r->customer?->name),
                TextColumn::make('amount')->label(__('টাকা'))->numeric(0)
                    ->description(fn (DeskPayment $r) => ((float) $r->discount > 0 ? __('ডিসকাউন্ট :d', ['d' => number_format((float) $r->discount)]).' · ' : '')
                        .($r->method ?? '').($r->trx_id ? ' · '.$r->trx_id : '')),
                TextColumn::make('source')->label(__('কোথা থেকে'))->badge()
                    ->formatStateUsing(fn ($state) => $state === 'desk' ? __('Desk') : __('বিলিং থেকে কপি'))
                    ->color(fn ($state) => $state === 'desk' ? 'info' : 'gray')
                    ->description(fn (DeskPayment $r) => $r->daily_bill_id ? __('বিলিং Daily Bill #:n', ['n' => $r->daily_bill_id]) : null),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskPayment::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskPayment::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskPayment $r) => $r->cancel_reason ?? ($r->transfer_to_ref
                        ? __('ID :c-এ', ['c' => DeskCustomer::find($r->transfer_to_ref)?->customer_code]) : ($r->note ? mb_strimwidth($r->note, 0, 60, '…') : null))),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('কোথা থেকে'))->options(['desk' => __('Desk'), 'ispdigital' => __('বিলিং থেকে কপি')]),
                SelectFilter::make('status')->label(__('অবস্থা'))->options(collect(DeskPayment::STATUSES)->map(fn ($s) => __($s[0]))->all()),
            ])
            ->recordActions([
                Action::make('receipt')->label(__('রিসিট'))->icon(Heroicon::OutlinedPrinter)->color('gray')
                    ->visible(fn (DeskPayment $r) => in_array($r->status, ['approved', 'pending'], true))
                    ->url(fn (DeskPayment $r) => route('desk-payments.receipt', ['company' => Filament::getTenant(), 'payment' => $r->id]), true),
                Action::make('cancel')->label(__('বাতিল'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (DeskPayment $r) => Ledger::canCancel($r, auth()->user()))
                    ->schema([Textarea::make('reason')->label(__('কেন বাতিল'))->required()->rows(2)->maxLength(1000)])
                    ->modalHeading(fn (DeskPayment $r) => __(':c: :a টাকার পেমেন্ট বাতিল', ['c' => $r->customer?->customer_code, 'a' => number_format((float) $r->amount)]))
                    ->modalDescription(__('পেমেন্টটা "বাতিল" হবে, খাতায় উল্টো সারি পড়বে আর বকেয়া সাথে সাথে বাড়বে। লাইন এখনই বন্ধ হবে না।'))
                    ->action(function (DeskPayment $r, array $data) {
                        try {
                            Ledger::cancel($r, (string) $data['reason'], auth()->user());
                            Notification::make()->success()->title(__('পেমেন্ট বাতিল হয়েছে'))->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title(__('হয়নি'))->body($e->getMessage())->send();
                        }
                    }),
                Action::make('transfer')->label(__('ট্রান্সফার'))->icon(Heroicon::OutlinedArrowsRightLeft)->color('warning')
                    ->visible(fn (DeskPayment $r) => Ledger::canTransfer($r, auth()->user()))
                    ->schema([
                        Select::make('to')->label(__('কোন কাস্টমারের ID-তে'))->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => DeskCustomer::where('company_id', Filament::getTenant()->getKey())
                                ->where(fn ($w) => $w->where('customer_code', $search)->orWhere('username', $search)->orWhere('name', 'like', '%'.$search.'%'))
                                ->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->customer_code} · {$c->name}"])->all())
                            ->getOptionLabelUsing(fn ($value) => ($c = DeskCustomer::find($value)) ? "{$c->customer_code} · {$c->name}" : null),
                        Textarea::make('reason')->label(__('কারণ'))->required()->rows(2)->maxLength(1000)
                            ->placeholder(__('যেমন: কাস্টমার ভুল ID-তে টাকা দিয়েছেন')),
                    ])
                    ->modalDescription(__('পুরনো ID-র খাতায় উল্টো সারি পড়বে, নতুন ID-তে জমা হবে। TrxID একই থাকবে।'))
                    ->action(function (DeskPayment $r, array $data) {
                        try {
                            $to = DeskCustomer::where('company_id', $r->company_id)->findOrFail($data['to']);
                            Ledger::transfer($r, $to, (string) $data['reason'], auth()->user());
                            Notification::make()->success()->title(__('ID :c-এ ট্রান্সফার হয়েছে', ['c' => $to->customer_code]))->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title(__('হয়নি'))->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
