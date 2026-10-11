<?php

namespace App\Filament\App;

use App\Models\BillingTicket;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** "New ticket" and "Assign" actions that write to the billing software (through the engine). */
class TicketActions
{
    private static function company(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    private static function options(string $key): array
    {
        try {
            return Engine::ticketOptions(static::company())[$key] ?? [];
        } catch (RuntimeException) {
            return [];
        }
    }

    /** Customer search from our own copy (billing_customers): ID, mobile, PPPoE ID or name. Left customers too, after the current ones. */
    private static function searchCustomers(string $search): array
    {
        $search = trim($search);
        $digits = preg_replace('/\D/', '', $search);

        return \App\Models\BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
            ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                ->orWhere('username', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 5, fn ($x) => $x->orWhere('mobile', 'like', "%{$digits}%")))
            ->orderByRaw("ltrim(customer_id, '0') = ltrim(?, '0') DESC", [$search])->orderBy('is_left')->orderBy('customer_id')->limit(15)
            ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'is_left', 'left_on'])
            ->map(fn ($c) => $c->only(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'is_left', 'left_on']))->all();
    }

    /** Info box under the customer: bill (our DB), connection (our MikroTik), OLT/ONU (our OLT, else billing). */
    private static function infoView(?string $customer)
    {
        return static::customerInfo((int) (explode('|', (string) $customer)[0] ?? 0));
    }

    /** [info, error] for a billing customer header id: bill, MikroTik now, OLT/ONU now (cached 60 s). */
    public static function liveInfo(int $headerId, bool $fresh = false): array
    {
        if (! $headerId) {
            return [null, null];
        }
        $key = 'ticket-info:'.static::company().':'.$headerId;
        if ($fresh) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
        try {
            return [\Illuminate\Support\Facades\Cache::remember($key, 60, fn () => Engine::ticketInfo(static::company(), $headerId)), null];
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
        return trim('ID '.$c['customer_id'].' · '.$c['name'].' · '.($c['mobile'] ?? '').($c['zone'] ? ' · '.$c['zone'] : ''), ' ·')
            .(! empty($c['is_left']) ? ' · LEFT'.(! empty($c['left_on']) ? ' ('.$c['left_on']->format('d M Y').')' : '') : '');
    }

    /** @param  string|null  $customerId  prefill (e.g. from a chat) */
    public static function newTicket(?string $customerId = null): Action
    {
        return Action::make('newTicket')->label('নতুন টিকিট')->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading('বিলিং সফটওয়্যারে নতুন টিকিট')
            ->modalDescription('কাস্টমার বাছলে তার লাইনের এখনকার অবস্থা দেখাবে')
            ->modalIcon(Heroicon::OutlinedTicket)
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('টিকিট খুলুন')
            ->fillForm(function () use ($customerId) {
                $data = ['priority' => '2', 'sms_client' => false, 'sms_employees' => false];
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
                Select::make('customer')->label('কাস্টমার')->required()->searchable()
                    ->helperText('কাস্টমার ID, মোবাইল বা username লিখে খুঁজুন')
                    ->getSearchResultsUsing(fn (string $search) => collect(static::searchCustomers($search))
                        ->mapWithKeys(fn ($c) => [static::customerKey($c) => static::customerLabel($c)])->all())
                    ->getOptionLabelUsing(fn ($value) => 'ID '.(explode('|', (string) $value)[1] ?? '').' · '.(explode('|', (string) $value)[2] ?? ''))
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('mobile', explode('|', (string) $state)[3] ?? null)),
                \Filament\Forms\Components\Placeholder::make('info')->hiddenLabel()->columnSpanFull()
                    ->visible(fn ($get) => filled($get('customer')))
                    ->content(fn ($get) => static::infoView($get('customer'))),
                Section::make('টিকিটের তথ্য')->icon(Heroicon::OutlinedPencilSquare)->schema([
                    Grid::make(2)->schema([
                        Select::make('category_id')->label('সমস্যার ধরন')->required()->searchable()
                            ->options(fn () => static::options('categories')),
                        Select::make('priority')->label('Priority')->required()
                            ->options(fn () => static::options('priorities') ?: ['1' => 'Low', '2' => 'Medium', '3' => 'High']),
                    ]),
                    TextInput::make('mobile')->label('যোগাযোগের মোবাইল')->tel()->required(),
                    Textarea::make('comment')->label('সমস্যার বিবরণ')->required()->rows(3)->maxLength(1900),
                    Grid::make(2)->schema([
                        Select::make('dept_id')->label('ডিপার্টমেন্ট (ঐচ্ছিক)')->options(fn () => static::options('departments')),
                        Select::make('employees')->label('কর্মী assign (ঐচ্ছিক)')->multiple()->searchable()
                            ->options(fn () => static::options('employees')),
                    ]),
                    Grid::make(2)->schema([
                        Toggle::make('sms_client')->label('কাস্টমারকে SMS'),
                        Toggle::make('sms_employees')->label('কর্মীকে SMS'),
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
                        'sms_client' => (bool) $data['sms_client'],
                        'employees' => array_values($data['employees'] ?? []), 'dept_id' => $data['dept_id'] ?? null,
                        'sms_employees' => (bool) $data['sms_employees'],
                    ]);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('টিকিট খোলা যায়নি')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()
                    ->title($res['complain_id'] ? "টিকিট #{$res['complain_id']} খোলা হয়েছে" : 'টিকিট খোলা হয়েছে')
                    ->body($res['message'] ?? null)->send();
            });
    }

    /** Pull the latest tickets from the billing software now (instead of waiting for the 5-minute sync). */
    public static function sync(): Action
    {
        return Action::make('sync')->label('এখনই Sync')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->action(function () {
                try {
                    $r = Engine::syncTickets(static::company());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Sync হয়নি')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('বিলিং থেকে আপডেট হয়েছে')
                    ->body("খোলা {$r['open']}টা, গত ৩ দিনে সমাধান {$r['solved']}টা")->send();
            });
    }

    /** Printable list of one employee's in-progress tickets (opens in a new tab). */
    public static function print(): Action
    {
        return Action::make('print')->label('প্রিন্ট')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->modalHeading('কাজ চলছে এমন টিকিট প্রিন্ট')
            ->modalSubmitActionLabel('প্রিন্ট পেজ খুলুন')
            ->schema([
                Select::make('employee')->label('কর্মী')->required()->searchable()
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

                        return collect($names)->mapWithKeys(fn ($c, $n) => [$n => "{$n} ({$c}টা)"])->all();
                    })
                    ->helperText('শুধু যাদের নামে এখন কাজ চলছে এমন টিকিট আছে'),
            ])
            ->action(function (array $data, $livewire) {
                $url = route('tickets.print', ['company' => Filament::getTenant()->slug, 'employee' => $data['employee']]);
                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    public static function assign(): Action
    {
        return Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')
            ->visible(fn (BillingTicket $record) => $record->isOpen())
            ->modalHeading(fn (BillingTicket $record) => "টিকিট #{$record->complain_id}: কর্মী assign")
            ->modalSubmitActionLabel('Assign করুন')
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
                Select::make('dept_id')->label('ডিপার্টমেন্ট (ঐচ্ছিক)')->options(fn () => static::options('departments')),
                Select::make('employees')->label('কর্মী')->multiple()->searchable()->required()
                    ->options(fn () => static::options('employees')),
                Toggle::make('sms_employees')->label('কর্মীকে SMS'),
            ])
            ->action(function (array $data, BillingTicket $record, Action $action) {
                try {
                    Engine::assign(static::company(), $record->complain_id, $data['employees'], $data['dept_id'] ?? null,
                        (bool) $data['sms_employees']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Assign করা যায়নি')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title("টিকিট #{$record->complain_id} assign হয়েছে")->send();
            });
    }

    /** "Edit": category, priority, complaint mobile, description and staff of an open ticket, saved in billing. */
    public static function edit(): Action
    {
        return Action::make('edit')->label('এডিট')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn (BillingTicket $record) => $record->isOpen())
            ->modalHeading(fn (BillingTicket $record) => "টিকিট #{$record->complain_id} এডিট")
            ->modalDescription('বিলিং সফটওয়্যারেও বদলাবে (আপনার নিজের বিলিং লগইনে)। কাস্টমারকে SMS যাবে না।')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('সেভ করুন')
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
                    Select::make('category_id')->label('সমস্যার ধরন')->required()->searchable()
                        ->options(fn () => static::options('categories')),
                    Select::make('priority')->label('Priority')->required()
                        ->options(fn () => static::options('priorities') ?: ['1' => 'Low', '2' => 'Medium', '3' => 'High']),
                ]),
                TextInput::make('mobile')->label('যোগাযোগের মোবাইল')->tel()->required(),
                Textarea::make('comment')->label('সমস্যার বিবরণ')->required()->rows(4)->maxLength(1900),
                Grid::make(2)->schema([
                    Select::make('dept_id')->label('ডিপার্টমেন্ট (ঐচ্ছিক)')->options(fn () => static::options('departments')),
                    Select::make('employees')->label('কর্মী')->multiple()->searchable()
                        ->helperText('খালি রাখলে কর্মী যেমন আছে থাকবে')
                        ->options(fn () => static::options('employees')),
                ]),
                Toggle::make('sms_employees')->label('কর্মীকে SMS'),
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
                    Notification::make()->danger()->title('এডিট হয়নি')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title("টিকিট #{$record->complain_id} আপডেট হয়েছে")->send();
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
                false => 'কাস্টমার MikroTik-এ অফলাইন, তাই Solve করা যাবে না',
                null => 'MikroTik থেকে উত্তর আসেনি; Solve চাপলে আবার যাচাই হবে',
                default => null,
            })
            ->modalHeading(fn (BillingTicket $record) => "টিকিট #{$record->complain_id} সমাধান")
            ->modalDescription('কাস্টমার এখন MikroTik-এ অনলাইন কিনা আবার যাচাই করে বিলিংয়ে Solved করা হবে। কাস্টমারকে SMS যাবে না।')
            ->modalSubmitActionLabel('Solve করুন')
            ->schema([
                Textarea::make('remark')->label('মন্তব্য (ঐচ্ছিক)')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, BillingTicket $record, Action $action) {
                try {
                    Engine::solve(static::company(), $record->complain_id, $data['remark'] ?? null);
                } catch (RuntimeException $e) {
                    \Illuminate\Support\Facades\Cache::forget('ticket-info:'.static::company().':'.(int) $record->customer_header_id);
                    Notification::make()->danger()->title('Solve হয়নি')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title("টিকিট #{$record->complain_id} সমাধান হয়েছে")->send();
            });
    }
}
