<?php

namespace App\Filament\App;

use App\Models\BillingCustomer;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * "Collect bill": the billing software's Daily Bill Collection -> Receive Bill, through the engine with the
 * member's own billing login. The collection stays pending until staff approve it in the billing software.
 */
class BillActions
{
    /** Enter in the amount fields saves the bill (the modal's submit button), like the billing form's Submit. */
    private const ENTER_SUBMITS = 'if ($el.form) { $el.form.requestSubmit() } else { $el.closest(".fi-modal-window")?.querySelector(".fi-modal-footer-actions button[type=submit], .fi-modal-footer button[type=submit]")?.click() }';

    private static function company(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    /** Engine's form data for one customer, cached briefly so the form's options and info box share one call. */
    private static function form(int $headerId, bool $fresh = false): array
    {
        $key = 'bill-form:'.static::company().':'.auth()->id().':'.$headerId;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 120, fn () => Engine::billForm(static::company(), $headerId));
    }

    private static function tryForm(?int $headerId): array
    {
        if (! $headerId) {
            return [null, null];
        }
        try {
            return [static::form($headerId), null];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    private static function defaults(array $f): array
    {
        return ['paid' => (float) ($f['payable'] ?? 0) ?: null, 'discount' => 0,
            'method_id' => $f['method_default'] ?? null, 'received_by_id' => $f['received_by_default'] ?? null,
            'bill_month' => $f['bill_month'] ?? null, 'expected_payable' => $f['payable'] ?? null,
            'sms' => false, 'next_billing_date' => false, 'print' => true];
    }

    private static function searchCustomers(string $search): array
    {
        $search = trim($search);
        $digits = preg_replace('/\D/', '', $search);

        return BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
            ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                ->orWhere('username', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 5, fn ($x) => $x->orWhere('mobile', 'like', "%{$digits}%")))
            ->orderByRaw("ltrim(customer_id, '0') = ltrim(?, '0') DESC", [$search])->orderBy('customer_id')->limit(15)
            ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
            ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])
            ->all();
    }

    /** @param  BillingCustomer|null  $customer  fixed customer (customer page); null = pick one in the form */
    public static function receive(?BillingCustomer $customer = null): Action
    {
        $headerOf = fn ($get) => $customer ? (int) $customer->header_id : (int) $get('header_id');

        return Action::make('receiveBill')->label('বিল নিন')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => \App\Support\Menu::can('bills'))
            ->modalHeading($customer ? "{$customer->name} (ID {$customer->customer_id}): বিল নিন" : 'বিল নিন')
            ->modalDescription('বিলিং সফটওয়্যারে Receive Bill হিসেবে জমা হবে। বিলিং-এ approve না করা পর্যন্ত বকেয়া কমবে না।')
            ->modalSubmitActionLabel('বিল জমা দিন')
            ->fillForm(function () use ($customer) {
                if (! $customer) {
                    return ['sms' => false, 'print' => true];
                }
                try {
                    return static::defaults(static::form((int) $customer->header_id, true));
                } catch (RuntimeException) {
                    return ['sms' => false, 'print' => true];  // the info box shows the error
                }
            })
            ->schema([
                Select::make('header_id')->label('কাস্টমার')->required(! $customer)->visible(! $customer)->searchable()->autofocus()
                    ->helperText('কাস্টমার ID, মোবাইল, username বা নাম লিখে খুঁজুন')
                    ->getSearchResultsUsing(fn (string $search) => static::searchCustomers($search))
                    ->getOptionLabelUsing(function ($value) {
                        $c = BillingCustomer::where('company_id', static::company())->where('header_id', (int) $value)->first();

                        return $c ? BillingCustomer::searchLabel($c) : (string) $value;
                    })
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, $livewire) {
                        [$f] = static::tryForm((int) $state ?: null);
                        foreach (static::defaults($f ?? []) as $k => $v) {
                            $set($k, $v);
                        }
                        // customer picked: straight to the amount, Enter there saves
                        $livewire->js("setTimeout(() => { const i = document.querySelector('input[id$=\\'.paid\\']'); if (i) { i.focus(); i.select(); } }, 300)");
                    }),
                Placeholder::make('info')->hiddenLabel()
                    ->visible(fn ($get) => (bool) $headerOf($get))
                    ->content(function ($get) use ($headerOf) {
                        [$f, $error] = static::tryForm($headerOf($get));

                        return view('filament.app.bills.form-info', ['info' => $f, 'error' => $error]);
                    }),
                Hidden::make('expected_payable'),
                Grid::make(2)->schema([
                    TextInput::make('paid')->label('টাকা নিলেন')->numeric()->minValue(0)->suffix('টাকা')->required()->live(debounce: 400)
                        ->autofocus((bool) $customer)
                        ->extraInputAttributes(['x-on:keydown.enter.prevent' => static::ENTER_SUBMITS]),
                    TextInput::make('discount')->label('ডিসকাউন্ট')->numeric()->minValue(0)->suffix('টাকা')->default(0)->live(debounce: 400)
                        ->extraInputAttributes(['x-on:keydown.enter.prevent' => static::ENTER_SUBMITS]),
                ]),
                // live sum like the billing form's Details box: due - discount - received = balance
                Placeholder::make('summary')->hiddenLabel()
                    ->visible(fn ($get) => (bool) $headerOf($get) && $get('expected_payable') !== null)
                    ->content(fn ($get) => view('filament.app.bills.summary', ['payable' => (float) $get('expected_payable'),
                        'discount' => (float) $get('discount'), 'paid' => (float) $get('paid')])),
                Grid::make(2)->schema([
                    Select::make('method_id')->label('পেমেন্টের মাধ্যম')->required()
                        ->options(fn ($get) => static::tryForm($headerOf($get))[0]['methods'] ?? []),
                    Select::make('received_by_id')->label('কে টাকা নিলেন (Received By)')->required()->searchable()
                        ->options(fn ($get) => static::tryForm($headerOf($get))[0]['received_by'] ?? []),
                    TextInput::make('trx_no')->label('Receipt / Transaction নম্বর')->maxLength(100)
                        ->regex('/^\S*$/')->validationMessages(['regex' => 'ফাঁকা জায়গা ছাড়া লিখুন']),
                    TextInput::make('bill_month')->label('বিলের মাস (রসিদে ছাপা হয়)')->maxLength(100),
                ]),
                TextInput::make('remarks')->label('নোট')->maxLength(500)
                    ->required(fn ($get) => (float) $get('discount') > 0
                        || abs((float) $get('paid') + (float) $get('discount') - (float) $get('expected_payable')) > 0.001)
                    ->helperText('আংশিক টাকা বা ডিসকাউন্ট হলে লিখতে হবে'),
                Grid::make(2)->schema([
                    Toggle::make('sms')->label('কাস্টমারকে SMS')
                        ->helperText('চালু করলে বিলিং SMS পাঠিয়ে তবে উত্তর দেয়, জমা হতে ২০-৬০ সেকেন্ড লাগে'),
                    Toggle::make('print')->label('জমার পর রিসিট প্রিন্ট')->default(true),
                    Toggle::make('next_billing_date')->label('পরের বিলিং তারিখ সেট করুন (Is it next billing date?)'),
                ]),
            ])
            ->action(function (array $data, Action $action, $livewire) use ($customer) {
                $headerId = $customer ? (int) $customer->header_id : (int) ($data['header_id'] ?? 0);
                try {
                    $res = Engine::receiveBill(static::company(), $headerId, [
                        'paid' => (float) $data['paid'], 'discount' => (float) ($data['discount'] ?? 0),
                        'method_id' => (string) $data['method_id'], 'received_by_id' => (string) $data['received_by_id'],
                        'trx_no' => $data['trx_no'] ?? '', 'remarks' => $data['remarks'] ?? '',
                        'bill_month' => $data['bill_month'] ?? null, 'sms' => (bool) ($data['sms'] ?? true),
                        'next_billing_date' => (bool) ($data['next_billing_date'] ?? false),
                        'expected_payable' => isset($data['expected_payable']) ? (float) $data['expected_payable'] : null,
                    ]);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('বিল নেওয়া হয়নি')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                } finally {
                    Cache::forget('bill-form:'.static::company().':'.auth()->id().':'.$headerId);
                }
                $receipt = ! empty($res['collection_id'])
                    ? route('receipts.print', ['company' => Filament::getTenant()->slug, 'collection' => $res['collection_id']]) : null;
                if ($receipt && ($data['print'] ?? true)) {
                    // print in a hidden frame on this page: no new tab; with Chrome's --kiosk-printing no dialog either
                    $livewire->js('(() => { const f = document.createElement("iframe"); f.style.cssText = "position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0"; f.src = '
                        .json_encode($receipt).'; document.body.appendChild(f); setTimeout(() => f.remove(), 120000); })()');
                }
                Notification::make()->success()->title('বিল জমা হয়েছে, বিলিং-এ approve বাকি')
                    ->body(trim(($res['message'] ?? '').' · বাকি বকেয়া '.($res['balance'] ?? 0).' টাকা', ' ·'))
                    ->actions(! empty($res['collection_id']) ? [Action::make('printReceipt')->label('রিসিট প্রিন্ট')->button()
                        ->url(route('receipts.print', ['company' => Filament::getTenant()->slug, 'collection' => $res['collection_id']]))
                        ->openUrlInNewTab()] : [])
                    ->persistent()->send();
            });
    }
}
