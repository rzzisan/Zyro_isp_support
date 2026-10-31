<?php

namespace App\Filament\App;

use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\Menu;
use App\Support\Sms;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
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
            'desk_sms' => static::deskSmsDefault(), 'next_billing_date' => static::nextBillingDate((float) ($f['payable'] ?? 0), (float) ($f['payable'] ?? 0), 0),
            'next_billing_on' => now('Asia/Dhaka')->toDateString(), 'print' => true];
    }

    /**
     * "পরের বিলিং তারিখ সেট" follows the amount (Zisan): on only when more than 80% of the due is paid, and off for an
     * advance (more than the due); a discount counts as paid. Staff can still flip it by hand after typing the amount.
     */
    public static function nextBillingDate(float $payable, float $paid, float $discount): bool
    {
        $total = $paid + $discount;

        return $payable > 0 && $total <= $payable + 0.001 && $total > 0.8 * $payable + 0.001;
    }

    /** "কাস্টমারকে SMS": the gateway settings' "বিল নিলে কাস্টমারকে SMS", when a bill-payment template is set. */
    private static function deskSmsDefault(): bool
    {
        $s = SmsSetting::forCompany(static::company());

        return $s->bill_sms && $s->billSmsReady();
    }

    private static function searchCustomers(string $search): array
    {
        $search = trim($search);

        return BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
            ->matching($search)
            ->orderByRaw("ltrim(customer_id, '0') = ltrim(?, '0') DESC", [$search])->orderBy('customer_id')->limit(15)
            ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
            ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])
            ->all();
    }

    /**
     * The bill-payment SMS after a saved collection (Desk's gateway, in the background); a short note for the toast.
     * Also used by the Android app's bill collection.
     */
    public static function queueBillSms(int $companyId, int $headerId, array $data, array $res, ?string $methodName): ?string
    {
        $c = BillingCustomer::where('company_id', $companyId)->where('header_id', $headerId)->first();
        if (! $c) {
            return null;
        }
        $paid = (float) $data['paid'];
        $discount = (float) ($data['discount'] ?? 0);
        $payable = isset($data['expected_payable']) ? (float) $data['expected_payable'] : (float) $c->due;
        $receipt = ! empty($res['collection_id'])
            ? (BillCollection::whereKey($res['collection_id'])->value('money_receipt_no') ?: 'D-'.$res['collection_id']) : '';
        try {
            $campaign = Sms::billPaid($companyId, auth()->id(), $c, [
                'paid' => $paid, 'discount' => $discount, 'payable' => $payable,
                'due' => isset($res['balance']) ? (float) $res['balance'] : $payable - $paid - $discount,
                'months' => $data['bill_month'] ?? '', 'method' => $methodName ?? '', 'receipt_no' => $receipt,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return __('SMS তালিকায় তোলা যায়নি');
        }
        if (! $campaign) {
            return __('SMS যায়নি (মোবাইল নম্বর ঠিক নেই)');
        }
        Engine::kickSms($companyId);

        return __('কাস্টমারকে SMS যাচ্ছে');
    }

    /** Title after a saved bill: being approved in the background (bill_approve) or waiting for approval in billing. */
    public static function savedTitle(array $res): string
    {
        return match (true) {
            ! empty($res['approved']) => __('বিল জমা ও approve হয়েছে'),
            ! empty($res['approving']) => __('বিল জমা হয়েছে, approve হচ্ছে'),
            default => __('বিল জমা হয়েছে, বিলিং-এ approve বাকি'),
        };
    }

    /**
     * @param  BillingCustomer|null  $customer  fixed customer (customer page); null = pick one in the form
     * @param  bool  $forRecord  table row action: the row's customer (customer list "Pay")
     */
    public static function receive(?BillingCustomer $customer = null, bool $forRecord = false): Action
    {
        $headerOf = fn ($get) => $customer ? (int) $customer->header_id : (int) $get('header_id');
        $nextDate = fn ($get, $set) => $get('expected_payable') === null ? null
            : $set('next_billing_date', static::nextBillingDate((float) $get('expected_payable'), (float) $get('paid'), (float) $get('discount')));

        return Action::make($forRecord ? 'payBill' : 'receiveBill')->label(__('বিল নিন'))->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => Menu::can('bills'))
            // bills are made with the member's own billing login, which only the owner sets on the team page
            ->disabled(fn () => ! \App\Models\Membership::mayUseBilling())
            ->tooltip(fn () => \App\Models\Membership::mayUseBilling() ? null : __('আপনার বিলিং লগইন দেওয়া নেই। Owner টিম পেজ থেকে আপনার বিলিং লগইন দিলে বিল নিতে আর লাইন চালু/বন্ধ করতে পারবেন।'))
            ->modalHeading(fn (?BillingCustomer $record = null) => ($c = $customer ?? ($forRecord ? $record : null))
                ? "{$c->name} (ID {$c->customer_id}): ".__('বিল নিন') : __('বিল নিন'))
            ->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitActionLabel(__('বিল জমা দিন'))
            ->fillForm(function (?BillingCustomer $record = null) use ($customer, $forRecord) {
                $c = $customer ?? ($forRecord ? $record : null);
                $plain = ['desk_sms' => static::deskSmsDefault(), 'next_billing_date' => true,
                    'next_billing_on' => now('Asia/Dhaka')->toDateString(), 'print' => true];
                if (! $c) {
                    return $plain;
                }
                $base = $forRecord ? ['header_id' => (int) $c->header_id] : [];
                try {
                    return $base + static::defaults(static::form((int) $c->header_id, true));
                } catch (RuntimeException) {
                    return $base + $plain;  // the info box shows the error
                }
            })
            ->schema([
                $forRecord ? Hidden::make('header_id') :
                Select::make('header_id')->label(__('কাস্টমার'))->required(! $customer)->visible(! $customer)->searchable()->autofocus()
                    ->searchDebounce(300)
                    // picking a customer re-renders only this form, not the whole page behind the modal (~900 KB on বিল কালেকশন)
                    ->partiallyRenderComponentsAfterStateUpdated(['billBody'])
                    ->placeholder(__('কাস্টমার ID, মোবাইল, PPPoE বা নাম লিখুন'))
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
                Group::make([
                    Placeholder::make('info')->hiddenLabel()
                        ->visible(fn ($get) => (bool) $headerOf($get))
                        ->content(function ($get) use ($headerOf) {
                            [$f, $error] = static::tryForm($headerOf($get));

                            return view('filament.app.bills.form-info', ['info' => $f, 'error' => $error]);
                        }),
                    Hidden::make('expected_payable'),
                    Grid::make(3)->schema([
                        TextInput::make('paid')->label(__('টাকা নিলেন'))->numeric()->minValue(0)->suffix(__('টাকা'))->required()->live(debounce: 400)
                            ->afterStateUpdated($nextDate)
                            ->partiallyRenderComponentsAfterStateUpdated(['summary', 'nextDate', 'nextDay'])
                            ->autofocus((bool) $customer || $forRecord)
                            ->extraInputAttributes(['x-on:keydown.enter.prevent' => static::ENTER_SUBMITS]),
                        TextInput::make('discount')->label(__('ডিসকাউন্ট'))->numeric()->minValue(0)->suffix(__('টাকা'))->default(0)->live(debounce: 400)
                            ->afterStateUpdated($nextDate)
                            ->partiallyRenderComponentsAfterStateUpdated(['summary', 'nextDate', 'nextDay'])
                            ->extraInputAttributes(['x-on:keydown.enter.prevent' => static::ENTER_SUBMITS]),
                        Select::make('method_id')->label(__('মাধ্যম'))->required()
                            ->options(fn ($get) => static::tryForm($headerOf($get))[0]['methods'] ?? []),
                    ]),
                    // live sum like the billing form's Details box: due - discount - received = balance
                    Placeholder::make('summary')->hiddenLabel()->key('summary')
                        ->visible(fn ($get) => (bool) $headerOf($get) && $get('expected_payable') !== null)
                        ->content(fn ($get) => view('filament.app.bills.summary', ['payable' => (float) $get('expected_payable'),
                            'discount' => (float) $get('discount'), 'paid' => (float) $get('paid')])),
                    Grid::make(3)->schema([
                        Select::make('received_by_id')->label('Received By')->required()->searchable()
                            ->options(fn ($get) => static::tryForm($headerOf($get))[0]['received_by'] ?? []),
                        TextInput::make('trx_no')->label(__('Trx নম্বর'))->maxLength(100)
                            ->regex('/^\S*$/')->validationMessages(['regex' => __('ফাঁকা জায়গা ছাড়া লিখুন')]),
                        TextInput::make('bill_month')->label(__('বিলের মাস'))->maxLength(100),
                    ]),
                    TextInput::make('remarks')->label(__('নোট'))->maxLength(500)
                        ->required(fn ($get) => (float) $get('discount') > 0
                            || abs((float) $get('paid') + (float) $get('discount') - (float) $get('expected_payable')) > 0.001)
                        ->placeholder(__('আংশিক, অগ্রিম বা ডিসকাউন্ট হলে লিখতে হবে')),
                    Grid::make(3)->schema([
                        Toggle::make('print')->label(__('জমার পর রিসিট প্রিন্ট'))->default(true),
                        // the customer's SMS goes only from the Desk's gateway; billing is always told not to send one
                        Toggle::make('desk_sms')->label(__('কাস্টমারকে SMS'))
                            ->disabled(fn () => ! SmsSetting::forCompany(static::company())->billSmsReady())
                            ->hintIcon('heroicon-o-information-circle', fn () => SmsSetting::forCompany(static::company())->billSmsReady()
                                ? __('জমা হলে Desk-এর SMS গেটওয়ে দিয়ে "বিল পরিশোধ" টেমপ্লেটের SMS যায় (জমা দেরি হয় না)')
                                : __('চালু করতে: সেটিংস → SMS গেটওয়ে-তে গেটওয়ে আর "বিল পরিশোধের টেমপ্লেট" সেট করুন')),
                        Toggle::make('next_billing_date')->label(__('পরের বিলিং তারিখ সেট'))->key('nextDate')
                            ->live()->partiallyRenderComponentsAfterStateUpdated(['nextDay'])
                            ->hintIcon('heroicon-o-information-circle', __('বিলিং-এর "Is it next billing date?" শুধু বকেয়ার ৮০%-এর বেশি নিলে নিজে চালু হয়, অগ্রিমে বন্ধ')),
                    ]),
                    // billing's switch only sets today; another day is written to the customer's Expire Date after the bill
                    DatePicker::make('next_billing_on')->label(__('পরের বিলিং তারিখ'))->key('nextDay')
                        ->visible(fn ($get) => (bool) $get('next_billing_date'))
                        ->default(fn () => now('Asia/Dhaka')->toDateString())
                        ->minDate(fn () => now('Asia/Dhaka')->subDays(31)->toDateString())->maxDate(fn () => now('Asia/Dhaka')->addDays(62)->toDateString())
                        ->helperText(__('আজকের তারিখ থাকলে বিলিং নিজেই সেট করে। অন্য তারিখ বাছলে বিল জমার পর কাস্টমারের Expire Date সেই দিনে (মাসের দিন) বদলানো হয়।')),
                ])->key('billBody'),
            ])
            ->action(function (array $data, Action $action, $livewire) use ($customer) {
                $headerId = $customer ? (int) $customer->header_id : (int) ($data['header_id'] ?? 0);
                $methodName = static::tryForm($headerId)[0]['methods'][(string) $data['method_id']] ?? null;
                try {
                    $res = Engine::receiveBill(static::company(), $headerId, [
                        'paid' => (float) $data['paid'], 'discount' => (float) ($data['discount'] ?? 0),
                        'method_id' => (string) $data['method_id'], 'received_by_id' => (string) $data['received_by_id'],
                        'trx_no' => $data['trx_no'] ?? '', 'remarks' => $data['remarks'] ?? '',
                        'bill_month' => $data['bill_month'] ?? null, 'sms' => false,  // never billing's SMS
                        'next_billing_date' => (bool) ($data['next_billing_date'] ?? true),
                        'next_billing_day' => ! empty($data['next_billing_on']) ? (int) \Illuminate\Support\Carbon::parse($data['next_billing_on'])->day : null,
                        'expected_payable' => isset($data['expected_payable']) ? (float) $data['expected_payable'] : null,
                        'approve' => Menu::can('bill_approve'),
                    ]);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(__('বিল নেওয়া হয়নি'))->body($e->getMessage())->persistent()->send();
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
                $smsNote = ($data['desk_sms'] ?? false) ? static::queueBillSms(static::company(), $headerId, $data, $res, $methodName) : null;
                Notification::make()->success()->title(static::savedTitle($res).($smsNote ? ' · '.$smsNote : ''))
                    ->body(trim(($res['message'] ?? '').' · '.(! empty($res['next_billing_day'])
                        ? __('পরের বিলিং তারিখ :day সেট হচ্ছে', ['day' => $res['next_billing_day']]).' · ' : '').((float) ($res['balance'] ?? 0) < 0
                        ? __('অগ্রিম :amount টাকা', ['amount' => -(float) $res['balance']])
                        : __('বাকি বকেয়া :amount টাকা', ['amount' => $res['balance'] ?? 0])), ' ·'))
                    ->actions(! empty($res['collection_id']) ? [Action::make('printReceipt')->label(__('রিসিট প্রিন্ট'))->button()
                        ->url(route('receipts.print', ['company' => Filament::getTenant()->slug, 'collection' => $res['collection_id']]))
                        ->openUrlInNewTab()] : [])
                    ->duration(3000)->send();
            });
    }
}
