<?php

namespace App\Filament\App;

use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\SmsSetting;
use App\Models\StaffTeam;
use App\Models\Technician;
use App\Services\Engine;
use App\Support\Sms;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Js;
use RuntimeException;

/** "New ticket" and "Assign" actions that write to the billing software (through the engine). */
class TicketActions
{
    private static function company(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    /** One billing list (categories, priorities, departments, employees) as [id => name]; the app passes its company. */
    public static function options(string $key, ?int $company = null): array
    {
        try {
            return Engine::ticketOptions($company ?? static::company())[$key] ?? [];
        } catch (RuntimeException) {
            return [];
        }
    }

    /** Customer search from our own copy (billing_customers): ID, mobile, PPPoE ID or name. Left customers too, after the current ones. */
    public static function searchCustomers(string $search): array
    {
        $search = trim($search);
        $digits = preg_replace('/\D/', '', $search);

        return BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
            ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                ->orWhere('username', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 5, fn ($x) => $x->orWhere('mobile', 'like', "%{$digits}%")))
            ->orderByRaw("ltrim(customer_id, '0') = ltrim(?, '0') DESC", [$search])->orderBy('is_left')->orderBy('customer_id')->limit(15)
            ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
            ->map(fn ($c) => $c->only(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on']))->all();
    }

    /** Info box under the customer: bill (our DB), connection (our MikroTik), OLT/ONU (our OLT, else billing). */
    private static function infoView(?string $customer)
    {
        return static::customerInfo((int) (explode('|', (string) $customer)[0] ?? 0));
    }

    /** [info, error] for a billing customer header id: bill, MikroTik now, OLT/ONU now (cached 60 s). */
    public static function liveInfo(int $headerId, bool $fresh = false, ?int $company = null): array
    {
        if (! $headerId) {
            return [null, null];
        }
        $company ??= static::company();
        $key = 'ticket-info:'.$company.':'.$headerId;
        if ($fresh) {
            Cache::forget($key);
        }
        try {
            return [Cache::remember($key, 60, fn () => Engine::ticketInfo($company, $headerId)), null];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /** The info box for the new-ticket form. */
    public static function customerInfo(int $headerId)
    {
        if (! $headerId) {
            return null;
        }
        [$info, $error] = static::liveInfo($headerId);

        return view('filament.app.tickets.customer-info', ['info' => $info, 'error' => $error]);
    }

    /** Compact ticket details: the ticket, then the customer's line now (MikroTik, OLT/ONU, bill). */
    public static function details(BillingTicket $ticket)
    {
        [$info, $error] = static::liveInfo((int) $ticket->customer_header_id);

        return view('filament.app.tickets.details', ['t' => $ticket, 'info' => $info, 'error' => $error]);
    }

    /** "header_id|customer_id|username|mobile" so the choice carries what the ticket needs. */
    private static function customerKey(array $c): string
    {
        return implode('|', [$c['header_id'], $c['customer_id'], $c['username'] ?? '', $c['mobile'] ?? '']);
    }

    private static function customerLabel(array $c): string
    {
        return BillingCustomer::searchLabel($c);
    }

    /**
     * @param  string|\Closure|null  $customerId  prefill (e.g. from a chat); a closure gets the table row and returns
     *                                            [customer id, problem description or null]
     */
    public static function newTicket(string|\Closure|null $customerId = null): Action
    {
        $prefill = fn (Action $action) => $customerId instanceof \Closure ? array_pad((array) $customerId($action->getRecord()), 2, null) : [$customerId, null];

        return Action::make('newTicket')->label(__('নতুন টিকিট'))->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading(__('বিলিং সফটওয়্যারে নতুন টিকিট'))
            ->modalDescription(function (Action $action) use ($prefill) {
                [$id] = $prefill($action);
                $open = $id ? BillingTicket::where('company_id', static::company())->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$id])
                    ->whereIn('state', ['pending', 'processing'])->orderByDesc('id')->value('complain_id') : null;

                return $open ? __('এই কাস্টমারের টিকিট #:id এখনও খোলা আছে', ['id' => $open]) : __('কাস্টমার বাছলে তার লাইনের এখনকার অবস্থা দেখাবে');
            })
            ->modalIcon(Heroicon::OutlinedTicket)
            ->modalWidth('4xl')
            ->modalSubmitActionLabel(__('টিকিট খুলুন'))
            ->fillForm(function (Action $action) use ($prefill) {
                [$customerId, $comment] = $prefill($action);
                $data = ['priority' => '2', 'sms_client' => SmsSetting::forCompany(static::company())->eventOn('ticket_open'),
                    'sms_employees' => false, 'comment' => $comment];
                if ($customerId) {
                    try {
                        $c = collect(static::searchCustomers($customerId))->firstWhere('customer_id', $customerId);
                        if ($c) {
                            $data['customer'] = static::customerKey($c);
                            $data['mobile'] = $c['mobile'] ?? null;
                        }
                    } catch (RuntimeException) {
                    }
                }

                return $data;
            })
            ->schema([
                Select::make('customer')->label(__('কাস্টমার'))->required()->searchable()
                    ->helperText(__('কাস্টমার ID, মোবাইল বা username লিখে খুঁজুন'))
                    ->getSearchResultsUsing(fn (string $search) => collect(static::searchCustomers($search))
                        ->mapWithKeys(fn ($c) => [static::customerKey($c) => static::customerLabel($c)])->all())
                    ->getOptionLabelUsing(function ($value) {
                        $c = BillingCustomer::where('company_id', static::company())->where('header_id', (int) explode('|', (string) $value)[0])->first();

                        return $c ? BillingCustomer::searchLabel($c)
                            : 'ID '.(explode('|', (string) $value)[1] ?? '').' · '.(explode('|', (string) $value)[2] ?? '');
                    })
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('mobile', explode('|', (string) $state)[3] ?? null)),
                Placeholder::make('info')->hiddenLabel()->columnSpanFull()
                    ->visible(fn ($get) => filled($get('customer')))
                    ->content(fn ($get) => static::infoView($get('customer'))),
                Section::make(__('টিকিটের তথ্য'))->icon(Heroicon::OutlinedPencilSquare)->schema([
                    Grid::make(2)->schema([
                        Select::make('category_id')->label(__('সমস্যার ধরন'))->required()->searchable()
                            ->options(fn () => static::options('categories')),
                        Select::make('priority')->label('Priority')->required()
                            ->options(fn () => static::options('priorities') ?: ['1' => 'Low', '2' => 'Medium', '3' => 'High']),
                    ]),
                    TextInput::make('mobile')->label(__('যোগাযোগের মোবাইল'))->tel()->required(),
                    Textarea::make('comment')->label(__('সমস্যার বিবরণ'))->required()->rows(3)->maxLength(1900),
                    Grid::make(2)->schema([
                        Select::make('dept_id')->label(__('ডিপার্টমেন্ট (ঐচ্ছিক)'))->options(fn () => static::options('departments')),
                        Select::make('employees')->label(__('কর্মী assign (ঐচ্ছিক)'))->multiple()->searchable()
                            ->options(fn () => static::options('employees'))
                            ->helperText(__('খালি রাখলে অটো assign সেটিং অনুযায়ী কর্মী পাবেন (ক্যাটাগরি দেখে)')),
                    ]),
                    Grid::make(2)->schema([
                        // the customer's SMS goes from the Desk gateway (template "টিকিট খোলা"), never billing's
                        Toggle::make('sms_client')->label(__('কাস্টমারকে SMS'))
                            ->disabled(fn () => ! SmsSetting::forCompany(static::company())->eventReady('ticket_open'))
                            ->hintIcon('heroicon-o-information-circle', fn () => SmsSetting::forCompany(static::company())->eventReady('ticket_open')
                                ? __('টিকিট খোলা হলে Desk-এর SMS গেটওয়ে দিয়ে "টিকিট খোলা" টেমপ্লেটের SMS যায় (যোগাযোগের মোবাইলে)')
                                : __('চালু করতে: সেটিংস → SMS গেটওয়ে-তে গেটওয়ে আর "টিকিট খোলা" টেমপ্লেট সেট করুন')),
                        Toggle::make('sms_employees')->label(__('কর্মীকে SMS')),
                    ]),
                ]),
            ])
            ->action(function (array $data, Action $action) {
                [$headerId, , $username] = array_pad(explode('|', (string) $data['customer']), 4, null);
                try {
                    $res = Engine::createTicket(static::company(), [
                        'header_id' => (int) $headerId, 'username' => $username,
                        'category_id' => $data['category_id'], 'priority' => (int) $data['priority'],
                        'mobile' => $data['mobile'], 'comment' => $data['comment'],
                        'sms_client' => false,  // never billing's SMS
                        'employees' => array_values($data['employees'] ?? []), 'dept_id' => $data['dept_id'] ?? null,
                        'sms_employees' => (bool) $data['sms_employees'],
                    ]);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(__('টিকিট খোলা যায়নি'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $smsNote = ($data['sms_client'] ?? false) ? static::queueTicketSms('ticket_open', (int) $headerId, $data['mobile'] ?? null, [
                    'ticket_no' => (string) ($res['complain_id'] ?? ''),
                    'problem' => static::options('categories')[$data['category_id']] ?? '',
                    // {status} goes into the customer's SMS: stays Bangla, not the staff's panel language
                    'status' => 'খোলা', 'employees' => array_values($data['employees'] ?? []),
                ]) : null;
                Notification::make()->success()
                    ->title(($res['complain_id'] ? __('টিকিট #:id খোলা হয়েছে', ['id' => $res['complain_id']]) : __('টিকিট খোলা হয়েছে')).($smsNote ? ' · '.$smsNote : ''))
                    ->body(trim(($res['message'] ?? '').(! empty($res['auto_assigned']) ? "\n".__('অটো assign: :who', ['who' => $res['auto_assigned']]) : '')) ?: null)->send();
            });
    }

    /** Pull the latest tickets from the billing software now (instead of waiting for the 5-minute sync). */
    public static function sync(): Action
    {
        return Action::make('sync')->label(__('এখনই Sync'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->action(function () {
                try {
                    $r = Engine::syncTickets(static::company());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(__('Sync হয়নি'))->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title(__('বিলিং থেকে আপডেট হয়েছে'))
                    ->body(__('খোলা :openটা, গত ৩ দিনে সমাধান :solvedটা', ['open' => $r['open'], 'solved' => $r['solved']]))->send();
            });
    }

    /** Printable list of one employee's in-progress tickets (opens in a new tab). */
    public static function print(): Action
    {
        return Action::make('print')->label(__('প্রিন্ট'))->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->modalHeading(__('কাজ চলছে এমন টিকিট প্রিন্ট'))
            ->modalSubmitActionLabel(__('প্রিন্ট পেজ খুলুন'))
            ->schema([
                Select::make('team')->label(__('ফিল্ড টিম'))->searchable()->live()
                    ->options(function () {
                        $names = static::options('employees');

                        return StaffTeam::where('company_id', static::company())->orderBy('name')->get()->mapWithKeys(fn ($t) => [
                            $t->id => $t->name.' ('.collect($t->members)->map(fn ($m) => $names[$m] ?? null)->filter()->join(', ').')',
                        ])->all();
                    })
                    ->helperText(__('টিম বাছলে টিমের সব সদস্যের টিকিট একসাথে প্রিন্ট হবে; একজন কর্মীর জন্য খালি রাখুন')),
                Select::make('employee')->label(__('কর্মী'))->searchable()
                    ->required(fn ($get) => blank($get('team')))->hidden(fn ($get) => filled($get('team')))
                    ->options(function () {
                        $names = [];
                        foreach (BillingTicket::where('company_id', static::company())->where('state', 'processing')
                            ->whereNotNull('assigned_to')->pluck('assigned_to') as $v) {
                            foreach (explode(',', $v) as $part) {
                                $n = trim(preg_replace('/\s*\(.*?\)\s*/u', '', $part));
                                if ($n !== '') {
                                    $names[$n] = ($names[$n] ?? 0) + 1;
                                }
                            }
                        }
                        ksort($names);

                        return collect($names)->mapWithKeys(fn ($c, $n) => [$n => $n.' '.__('(:countটা)', ['count' => $c])])->all();
                    })
                    ->helperText(__('শুধু যাদের নামে এখন কাজ চলছে এমন টিকিট আছে')),
            ])
            ->action(function (array $data, $livewire) {
                $url = route('tickets.print', ['company' => Filament::getTenant()->slug]
                    + (filled($data['team'] ?? null) ? ['team' => $data['team']] : ['employee' => $data['employee']]));
                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    public static function assign(): Action
    {
        return Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')
            ->visible(fn (BillingTicket $record) => $record->isOpen())
            ->modalHeading(fn (BillingTicket $record) => __('টিকিট #:id: কর্মী assign', ['id' => $record->complain_id]))
            ->modalSubmitActionLabel(__('Assign করুন'))
            ->fillForm(function (BillingTicket $record) {
                try {
                    $s = Engine::solvers(static::company(), $record->complain_id);
                } catch (RuntimeException) {
                    $s = [];
                }

                return ['dept_id' => isset($s['DepartmentId']) ? (string) $s['DepartmentId'] : null,
                    'employees' => array_map('strval', $s['EmployeeIds'] ?? []), 'sms_employees' => false];
            })
            ->schema([
                Select::make('dept_id')->label(__('ডিপার্টমেন্ট (ঐচ্ছিক)'))->options(fn () => static::options('departments')),
                Select::make('employees')->label(__('কর্মী'))->multiple()->searchable()->required()
                    ->options(fn () => static::options('employees')),
                Toggle::make('sms_employees')->label(__('কর্মীকে SMS')),
            ])
            ->action(function (array $data, BillingTicket $record, Action $action) {
                try {
                    Engine::assign(static::company(), $record->complain_id, $data['employees'], $data['dept_id'] ?? null,
                        (bool) $data['sms_employees']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(__('Assign করা যায়নি'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title(__('টিকিট #:id assign হয়েছে', ['id' => $record->complain_id]))->send();
            });
    }

    /** [previous key, next key] of a ticket in the list as it is shown now (tab, filters, search, sort). */
    private static function neighbours(BillingTicket $record, $livewire): array
    {
        static $memo = [];
        $id = $record->getKey();
        if (! array_key_exists($id, $memo)) {
            try {
                $keys = $livewire->getFilteredSortedTableQuery()->toBase()->pluck('billing_tickets.id')->map(fn ($k) => (string) $k)->all();
            } catch (\Throwable) {
                $keys = [];
            }
            $i = array_search((string) $id, $keys, true);
            $memo[$id] = $i === false ? [null, null] : [$keys[$i - 1] ?? null, $keys[$i + 1] ?? null];
        }

        return $memo[$id];
    }

    /** "← আগের" / "পরের →" in the ticket details: open the neighbouring ticket's details; the arrow keys do the same. */
    public static function step(int $dir): Action
    {
        $key = fn (BillingTicket $record, $livewire) => static::neighbours($record, $livewire)[$dir < 0 ? 0 : 1];
        $arrow = $dir < 0 ? 'arrow-left' : 'arrow-right';

        return Action::make($dir < 0 ? 'previousTicket' : 'nextTicket')
            ->label($dir < 0 ? __('আগের') : __('পরের'))->color('gray')->button()
            ->icon($dir < 0 ? Heroicon::OutlinedChevronLeft : Heroicon::OutlinedChevronRight)
            ->iconPosition($dir < 0 ? IconPosition::Before : IconPosition::After)
            ->tooltip($dir < 0 ? __('আগের টিকিট (← কী)') : __('পরের টিকিট (→ কী)'))
            ->disabled(fn (BillingTicket $record, $livewire) => $key($record, $livewire) === null)
            ->alpineClickHandler(fn (BillingTicket $record, $livewire) => ($k = $key($record, $livewire)) === null ? 'void 0'
                : '$wire.replaceMountedAction('.Js::from('view').', {}, '.Js::from(['table' => true, 'recordKey' => $k]).')')
            // arrow keys, unless typing or another (nested) modal is on top of the details
            ->extraAttributes(["x-on:keydown.{$arrow}.window" => "if (! \$event.target.closest('input, textarea, select, [contenteditable]') && ! \$el.disabled && document.querySelectorAll('.fi-modal-open').length === 1) \$el.click()"]);
    }

    /** "Edit": category, priority, complaint mobile, description and staff of an open ticket, saved in billing. */
    public static function edit(): Action
    {
        return Action::make('edit')->label(__('এডিট'))->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn (BillingTicket $record) => $record->isOpen())
            ->modalHeading(fn (BillingTicket $record) => __('টিকিট #:id এডিট', ['id' => $record->complain_id]))
            ->modalDescription(__('বিলিং সফটওয়্যারেও বদলাবে (আপনার নিজের বিলিং লগইনে)। কাস্টমারকে SMS যাবে না।'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('সেভ করুন'))
            ->fillForm(function (BillingTicket $record) {
                try {
                    $s = Engine::solvers(static::company(), $record->complain_id);
                } catch (RuntimeException) {
                    $s = [];
                }
                $raw = $record->raw ?? [];

                return [
                    'category_id' => isset($raw['ProblemCategoryId']) ? (string) $raw['ProblemCategoryId'] : null,
                    'priority' => isset($raw['ProblemPriorityId']) ? (string) $raw['ProblemPriorityId'] : '2',
                    'mobile' => $record->complainNumber() ?? $record->mobile,
                    'comment' => (string) $record->description,
                    'dept_id' => isset($s['DepartmentId']) ? (string) $s['DepartmentId'] : null,
                    'employees' => array_map('strval', $s['EmployeeIds'] ?? []),
                    'sms_employees' => false,
                ];
            })
            ->schema([
                Grid::make(2)->schema([
                    Select::make('category_id')->label(__('সমস্যার ধরন'))->required()->searchable()
                        ->options(fn () => static::options('categories')),
                    Select::make('priority')->label('Priority')->required()
                        ->options(fn () => static::options('priorities') ?: ['1' => 'Low', '2' => 'Medium', '3' => 'High']),
                ]),
                TextInput::make('mobile')->label(__('যোগাযোগের মোবাইল'))->tel()->required(),
                Textarea::make('comment')->label(__('সমস্যার বিবরণ'))->required()->rows(4)->maxLength(1900),
                Grid::make(2)->schema([
                    Select::make('dept_id')->label(__('ডিপার্টমেন্ট (ঐচ্ছিক)'))->options(fn () => static::options('departments')),
                    Select::make('employees')->label(__('কর্মী'))->multiple()->searchable()
                        ->helperText(__('খালি রাখলে কর্মী যেমন আছে থাকবে'))
                        ->options(fn () => static::options('employees')),
                ]),
                Toggle::make('sms_employees')->label(__('কর্মীকে SMS')),
            ])
            ->action(function (array $data, BillingTicket $record, Action $action) {
                // re-assign only when the staff list really changed (AddSolver restarts the assignment)
                $employees = array_map('strval', array_values($data['employees'] ?? []));
                try {
                    $now = array_map('strval', Engine::solvers(static::company(), $record->complain_id)['EmployeeIds'] ?? []);
                } catch (RuntimeException) {
                    $now = [];
                }
                sort($employees);
                sort($now);
                if ($employees === $now) {
                    $employees = [];
                }
                try {
                    Engine::editTicket(static::company(), $record->complain_id, [
                        'category_id' => $data['category_id'], 'priority' => (int) $data['priority'],
                        'mobile' => $data['mobile'], 'comment' => $data['comment'],
                        'employees' => $employees, 'dept_id' => $data['dept_id'] ?? null,
                        'sms_employees' => (bool) ($data['sms_employees'] ?? false),
                    ]);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(__('এডিট হয়নি'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title(__('টিকিট #:id আপডেট হয়েছে', ['id' => $record->complain_id]))->send();
            });
    }

    /** "Solve" in the ticket details: only while the customer is online on MikroTik (the engine checks again). */
    public static function solve(): Action
    {
        $online = function (BillingTicket $record): ?bool {
            [$info] = static::liveInfo((int) $record->customer_header_id);
            $mk = $info['mikrotik'] ?? null;

            return $mk === null ? null : (bool) ($mk['online'] ?? false);
        };

        return Action::make('solve')->label('Solve')->icon(Heroicon::OutlinedCheckCircle)->color('success')->button()
            ->visible(fn (BillingTicket $record) => $record->isOpen())
            ->disabled(fn (BillingTicket $record) => $online($record) === false)
            ->tooltip(fn (BillingTicket $record) => match ($online($record)) {
                false => ! empty(static::liveInfo((int) $record->customer_header_id)[0]['mikrotik']['blocked'])
                    ? __('কাস্টমারের লাইন MikroTik-এ ব্লক (ইন্টারনেট নেই), তাই Solve করা যাবে না')
                    : __('কাস্টমার MikroTik-এ অফলাইন, তাই Solve করা যাবে না'),
                null => __('MikroTik থেকে উত্তর আসেনি; Solve চাপলে আবার যাচাই হবে'),
                default => null,
            })
            ->modalHeading(fn (BillingTicket $record) => __('টিকিট #:id সমাধান', ['id' => $record->complain_id]))
            ->modalDescription(__('কাস্টমার এখন MikroTik-এ অনলাইন কিনা আবার যাচাই করে বিলিংয়ে Solved করা হবে। বিলিং থেকে কোনো SMS যায় না।'))
            ->modalSubmitActionLabel(__('Solve করুন'))
            ->fillForm(fn () => ['sms' => SmsSetting::forCompany(static::company())->eventOn('ticket_solved')])
            ->schema([
                Textarea::make('remark')->label(__('মন্তব্য (ঐচ্ছিক)'))->rows(2)->maxLength(500),
                Toggle::make('sms')->label(__('কাস্টমারকে SMS'))
                    ->disabled(fn () => ! SmsSetting::forCompany(static::company())->eventReady('ticket_solved'))
                    ->hintIcon('heroicon-o-information-circle', fn () => SmsSetting::forCompany(static::company())->eventReady('ticket_solved')
                        ? __('সমাধান হলে Desk-এর SMS গেটওয়ে দিয়ে "টিকিট সমাধান" টেমপ্লেটের SMS যায়')
                        : __('চালু করতে: সেটিংস → SMS গেটওয়ে-তে গেটওয়ে আর "টিকিট সমাধান" টেমপ্লেট সেট করুন')),
            ])
            ->action(function (array $data, BillingTicket $record, Action $action) {
                try {
                    Engine::solve(static::company(), $record->complain_id, $data['remark'] ?? null);
                } catch (RuntimeException $e) {
                    Cache::forget('ticket-info:'.static::company().':'.(int) $record->customer_header_id);
                    Notification::make()->danger()->title(__('Solve হয়নি'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $smsNote = ($data['sms'] ?? false) ? static::queueTicketSms('ticket_solved', (int) $record->customer_header_id,
                    $record->mobile, ['ticket_no' => (string) $record->complain_id, 'problem' => (string) $record->category,
                        'status' => 'সমাধান', 'technician' => (string) ($record->solved_by ?: $record->assigned_to)]) : null;
                Notification::make()->success()->title(__('টিকিট #:id সমাধান হয়েছে', ['id' => $record->complain_id]).($smsNote ? ' · '.$smsNote : ''))->send();
            });
    }

    /**
     * The ticket SMS (template of $type chosen in the gateway settings) through the Desk's gateway, in the background;
     * a short note for the toast. $ticket['employees'] (billing employee ids) fill {technician} / {technician_phone}.
     */
    public static function queueTicketSms(string $type, int $headerId, ?string $mobile, array $ticket, ?int $company = null): ?string
    {
        $company ??= static::company();
        $c = $headerId ? BillingCustomer::where('company_id', $company)->where('header_id', $headerId)->first() : null;
        if ($ids = array_map('strval', $ticket['employees'] ?? [])) {
            $names = static::options('employees', $company);
            $ticket['technician'] = collect($ids)->map(fn ($id) => $names[$id] ?? null)->filter()->implode(', ');
            $phone = Technician::where('company_id', $company)->whereIn('billing_employee_id', $ids)
                ->whereNotNull('wa_number')->value('wa_number');
            $ticket['technician_phone'] = $phone ? preg_replace('/^88(?=01)/', '', (string) $phone) : '';
        }
        try {
            $campaign = Sms::ticket($company, auth()->id(), $type, $c, $mobile, $ticket);
        } catch (\Throwable $e) {
            report($e);

            return __('SMS তালিকায় তোলা যায়নি');
        }
        if (! $campaign) {
            return __('SMS যায়নি (মোবাইল নম্বর ঠিক নেই)');
        }
        Engine::kickSms($company);

        return __('কাস্টমারকে SMS যাচ্ছে');
    }
}
