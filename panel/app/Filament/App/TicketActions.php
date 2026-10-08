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

    /** Info box under the customer: bill (our DB), connection (our MikroTik), OLT/ONU (billing). */
    private static function infoView(?string $customer)
    {
        $headerId = (int) (explode('|', (string) $customer)[0] ?? 0);
        if (! $headerId) {
            return null;
        }
        try {
            $info = \Illuminate\Support\Facades\Cache::remember('ticket-info:'.static::company().':'.$headerId, 60,
                fn () => Engine::ticketInfo(static::company(), $headerId));
            $error = null;
        } catch (RuntimeException $e) {
            $info = null;
            $error = $e->getMessage();
        }

        return view('filament.app.tickets.customer-info', ['info' => $info, 'error' => $error]);
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
            ->modalSubmitActionLabel('টিকিট খুলুন')
            ->fillForm(function () use ($customerId) {
                $data = ['priority' => '2', 'sms_client' => false, 'sms_employees' => true];
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
                \Filament\Forms\Components\Placeholder::make('info')->hiddenLabel()
                    ->visible(fn ($get) => filled($get('customer')))
                    ->content(fn ($get) => static::infoView($get('customer'))),
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
                    'employees' => array_map('strval', $s['EmployeeIds'] ?? []), 'sms_employees' => true];
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
}
