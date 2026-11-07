<?php

namespace App\Filament\App\Pages;

use App\Models\DeskBill;
use App\Models\DeskCustomer;
use App\Support\Ledger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use RuntimeException;

/** Desk খাতা: বিল (billing phase 4b): the ledger's monthly bills, with edit/void (bill_edit, reason, every version kept). */
class BillingLedgerBills extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'billing-ledger-bills';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    #[Url]
    public ?string $customer = null;

    public function getTitle(): string|Htmlable
    {
        return $this->customer ? __('Desk খাতা: বিল (ID :c)', ['c' => $this->customer]) : __('Desk খাতা: বিল');
    }

    public static function canAccess(): bool
    {
        return BillingLedger::canAccess();
    }

    public function getSubheading(): ?string
    {
        return __('বিল সংশোধন বা বাতিল করলে আগের টাকা ইতিহাসে থাকে, পার্থক্যটা খাতায় সমন্বয় সারি হয়ে পড়ে আর বকেয়া সাথে সাথে বদলায়। হস্তান্তরের আগে শুধু খাতার পরীক্ষার অ্যাকাউন্টে; বাকিদের বিল বিলিং-এই ঠিক করুন।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('payments')->label(__('পেমেন্ট'))->icon(Heroicon::OutlinedBanknotes)->color('gray')
                ->url(fn () => BillingLedger::getUrl($this->customer ? ['customer' => $this->customer] : [])),
        ];
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(DeskBill::query()->with('customer')->where('company_id', $company->getKey())
                ->when(filled($this->customer), fn ($q) => $q->whereIn('customer_ref', DeskCustomer::where('company_id', $company->getKey())
                    ->where(fn ($w) => $w->where('customer_code', $this->customer)->orWhere('username', $this->customer))->select('id'))))
            ->defaultSort('period_start', 'desc')
            ->deferFilters(false)
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('period_start')->label(__('মাস'))->date('M Y')->description(fn (DeskBill $r) => $r->package_name),
                TextColumn::make('customer.customer_code')->label('ID')->url(fn (DeskBill $r) => BillingLedger::customerUrl($r->customer))
                    ->description(fn (DeskBill $r) => $r->customer?->name),
                TextColumn::make('amount')->label(__('টাকা'))->numeric(0)
                    ->description(function (DeskBill $r) {
                        $v = DB::table('desk_bill_versions')->where('bill_ref', $r->id)->where('company_id', $r->company_id)->orderByDesc('id')->first();

                        return $v ? __('আগে :a · :r', ['a' => number_format((float) $v->old_amount), 'r' => mb_strimwidth((string) $v->reason, 0, 50, '…')]) : null;
                    }),
                TextColumn::make('source')->label(__('কোথা থেকে'))->badge()
                    ->formatStateUsing(fn ($state) => $state === 'desk' ? __('Desk') : __('বিলিং থেকে কপি'))
                    ->color(fn ($state) => $state === 'desk' ? 'info' : 'gray'),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskBill::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskBill::STATUSES[$state][1] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))->options(collect(DeskBill::STATUSES)->map(fn ($s) => __($s[0]))->all()),
            ])
            ->recordActions([
                Action::make('edit')->label(__('সংশোধন'))->icon(Heroicon::OutlinedPencilSquare)->color('warning')
                    ->visible(fn (DeskBill $r) => Ledger::canEditBill($r, auth()->user()))
                    ->modalHeading(fn (DeskBill $r) => __(':c: :m-এর বিল (:a টাকা)', ['c' => $r->customer?->customer_code,
                        'm' => $r->period_start->format('M Y'), 'a' => number_format((float) $r->amount)]))
                    ->fillForm(fn (DeskBill $r) => ['amount' => (float) $r->amount, 'void' => false])
                    ->schema([
                        Toggle::make('void')->label(__('বিলটা বাতিল করুন (টাকা শূন্য)'))->live(),
                        TextInput::make('amount')->label(__('নতুন টাকা'))->numeric()->minValue(0)->maxValue(1000000)->suffix(__('টাকা'))
                            ->required(fn ($get) => ! $get('void'))->hidden(fn ($get) => (bool) $get('void')),
                        Textarea::make('reason')->label(__('কারণ'))->required()->rows(2)->maxLength(1000),
                    ])
                    ->action(function (DeskBill $r, array $data) {
                        try {
                            Ledger::editBill($r, ($data['void'] ?? false) ? null : (float) $data['amount'], (string) $data['reason'], auth()->user());
                            Notification::make()->success()->title(__('বিল সংশোধন হয়েছে'))->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title(__('হয়নি'))->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
