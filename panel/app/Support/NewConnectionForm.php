<?php

namespace App\Support;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskConnection;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * নতুন সংযোগ / নতুন কাস্টমার form (billing phase 5, plan 2.4খ), used on the কাস্টমার list and the নতুন সংযোগ page.
 * Saving sends the customer to billing (engine/desk_connection.py, the member's billing login; billing makes the PPP
 * secret), then the engine reads the router back and copies the new customer into the Desk's list at once.
 * Only members with the "নতুন কাস্টমার যোগ" permission (customer_create; the owner always), and only while
 * বিলিং নিয়ম → connection_control is not off.
 */
class NewConnectionForm
{
    public static function canCreate(): bool
    {
        return Menu::can('customer_create');
    }

    public static function switchedOff(): bool
    {
        return (BillingSetting::forCompany(Filament::getTenant())->connection_control ?? 'off') === 'off';
    }

    /** The Desk's copy of the customer just made (the engine copies it before answering). */
    public static function customer(array $r): ?BillingCustomer
    {
        return ($r['header_id'] ?? null)
            ? BillingCustomer::where('company_id', Filament::getTenant()->getKey())->where('header_id', $r['header_id'])->first()
            : null;
    }

    /** billing's fixed lists for the form (Server, Zone, Package, ...), kept 10 minutes. */
    public static function lists(): array
    {
        $cid = Filament::getTenant()->getKey();

        return Cache::remember("new-connection-lists:{$cid}", 600, fn () => Engine::newConnectionList($cid, 'options'));
    }

    /** [id => name] for one of billing's selects. */
    public static function opts(string $name): array
    {
        return collect(static::lists()[$name] ?? [])->mapWithKeys(fn ($o) => [(string) $o['id'] => $o['name']])->all();
    }

    /** A dependent list (profiles of a Server, sub zones of a Zone, boxes of a Sub Zone), kept 10 minutes. */
    public static function remote(string $kind, $id): array
    {
        if (! $id) {
            return [];
        }
        $cid = Filament::getTenant()->getKey();
        try {
            $r = Cache::remember("new-connection-{$kind}:{$cid}:{$id}", 600, fn () => Engine::newConnectionList($cid, $kind, (int) $id));
        } catch (RuntimeException) {
            return [];
        }
        if ($kind === 'profiles') {
            return collect($r['profiles'] ?? [])->mapWithKeys(fn ($p) => [$p => $p])->all();
        }

        return collect($r['items'] ?? [])->mapWithKeys(fn ($o) => [(string) $o['id'] => $o['name']])->all();
    }

    public static function newPassword(): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 10))->map(fn () => $chars[random_int(0, strlen($chars) - 1)])->implode('');
    }

    /** Personal / Free / Left: no bill, no expire day (plan 2.4খ). */
    public static function noBill($statusId): bool
    {
        return in_array(mb_strtolower((string) (static::opts('Status')[(string) $statusId] ?? '')), ['personal', 'free', 'left'], true);
    }

    public static function usernameNote(?string $username): ?string
    {
        $username = trim((string) $username);
        if (! preg_match('/^[A-Za-z0-9._@-]{3,40}$/', $username)) {
            return __('ইংরেজি অক্ষর, সংখ্যা আর . _ - @ (৩-৪০)');
        }
        try {
            $taken = Engine::newConnectionList(Filament::getTenant()->getKey(), 'username', 0, $username)['taken'] ?? null;
        } catch (RuntimeException) {
            return null;
        }

        return $taken ? __('⚠ এই username বিলিং-এ আগে থেকেই আছে') : __('✓ বিলিং-এ ফাঁকা');
    }

    public static function action(string $name = 'create'): Action
    {
    return Action::make($name)->label(__('নতুন সংযোগ'))->icon(Heroicon::OutlinedUserPlus)
            ->modalWidth('4xl')
            ->disabled(fn () => (BillingSetting::forCompany(Filament::getTenant())->connection_control ?? 'off') === 'off')
            ->tooltip(fn () => (BillingSetting::forCompany(Filament::getTenant())->connection_control ?? 'off') === 'off'
                ? __('বিলিং নিয়মে "Desk থেকে নতুন সংযোগ" বন্ধ') : null)
            ->fillForm(function () {
                $today = Carbon::now('Asia/Dhaka');

                return [
                    'joined' => $today->toDateString(),
                    'bill_start' => $today->copy()->startOfMonth()->addMonth()->format('m/Y'),
                    'expire_day' => min($today->day + 2, 28),
                    'password' => static::newPassword(),
                    'status_id' => array_search('Active', static::opts('Status'), true) ?: null,
                    'connection_type_id' => array_search('Fiber', static::opts('ConnectionTypeId'), true) ?: null,
                    'customer_type_id' => array_search('Home', static::opts('CustomerTypeId'), true) ?: null,
                ];
            })
            ->schema([
                Tabs::make()->tabs([
                    Tab::make(__('মূল তথ্য'))->columns(2)->schema([
                        TextInput::make('name')->label(__('নাম'))->required()->maxLength(150),
                        TextInput::make('mobile')->label(__('মোবাইল'))->required()->tel()->regex('/^(\+?88)?01\d{9}$/')
                            ->helperText(__('01XXXXXXXXX')),
                        Select::make('zone_id')->label(__('Zone'))->required()->options(fn () => static::opts('ZoneId'))->live()
                            ->afterStateUpdated(fn ($set) => $set('sub_zone_id', null)),
                        Select::make('sub_zone_id')->label(__('Sub Zone'))->placeholder(__('ঐচ্ছিক'))->options(fn ($get) => static::remote('subzones', $get('zone_id')))->live()
                            ->afterStateUpdated(fn ($set) => $set('box_id', null)),
                        Select::make('box_id')->label(__('Box'))->placeholder(__('ঐচ্ছিক'))->options(fn ($get) => static::remote('boxes', $get('sub_zone_id'))),
                        Select::make('server_id')->label(__('রাউটার (Server)'))->required()->options(fn () => static::opts('ServerId'))->live()
                            ->afterStateUpdated(fn ($set) => $set('profile', null)),
                        Select::make('package_id')->label(__('প্যাকেজ'))->required()->searchable()->options(fn () => static::opts('PackageId'))->live()
                            ->afterStateUpdated(function ($state, $set) {
                                if (! $state) {
                                    return;
                                }
                                try {
                                    $bill = Engine::newConnectionList(Filament::getTenant()->getKey(), 'bill', (int) $state)['bill'] ?? null;
                                } catch (RuntimeException) {
                                    $bill = null;
                                }
                                if ($bill !== null) {
                                    $set('monthly_bill', (int) $bill);
                                }
                            }),
                        Select::make('profile')->label(__('MikroTik profile (Speed)'))->required()->searchable()
                            ->options(fn ($get) => static::remote('profiles', $get('server_id')))
                            ->helperText(__('আগে রাউটার বাছুন')),
                        TextInput::make('monthly_bill')->label(__('মাসিক বিল'))->numeric()->minValue(0)->prefix('৳')
                            ->required(fn ($get) => ! static::noBill($get('status_id'))),
                        Select::make('status_id')->label(__('Status'))->required()->options(fn () => static::opts('Status'))->live(),
                        Select::make('connection_type_id')->label(__('Connection Type'))->required()->options(fn () => static::opts('ConnectionTypeId')),
                        Select::make('customer_type_id')->label(__('Customer Type'))->required()->options(fn () => static::opts('CustomerTypeId')),
                        TextInput::make('username')->label(__('PPPoE UserName'))->required()->maxLength(40)
                            ->regex('/^[A-Za-z0-9._@-]{3,40}$/')->live(onBlur: true)
                            ->helperText(fn ($state) => static::usernameNote($state)),
                        TextInput::make('password')->label(__('PPPoE Password'))->required()->minLength(6)->maxLength(40)
                            ->helperText(__('কাস্টমারকে দিন; Desk-এ রাখা হয় না, এই ফর্ম বন্ধ হলে আর দেখা যাবে না।')),
                        DatePicker::make('joined')->label(__('সংযোগের তারিখ'))->required()->native(false)->maxDate(now()->addDays(7)),
                        TextInput::make('bill_start')->label(__('বিল শুরুর মাস (MM/YYYY)'))->required()->regex('/^(0[1-9]|1[0-2])\/\d{4}$/'),
                        TextInput::make('expire_day')->label(__('Expire Date (দিন)'))->numeric()->integer()->minValue(1)->maxValue(28)
                            ->required(fn ($get) => ! static::noBill($get('status_id'))),
                    ]),
                    Tab::make(__('অন্যান্য (ঐচ্ছিক)'))->columns(2)->schema([
                        TextInput::make('phone')->label(__('ফোন'))->maxLength(20),
                        TextInput::make('address')->label(__('ঠিকানা'))->maxLength(300),
                        Select::make('district_id')->label(__('জেলা'))->options(fn () => static::opts('DistrictId')),
                        Select::make('thana_id')->label(__('থানা'))->options(fn () => static::opts('ThanaId')),
                        Toggle::make('vip')->label(__('VIP')),
                        TextInput::make('remarks')->label(__('মন্তব্য'))->maxLength(300),
                    ]),
                ]),
            ])
            ->modalDescription(__('সেভ করলে কাস্টমার সাথে সাথে বিলিং-এ তৈরি হবে আর রাউটারে PPP secret বসবে। স্বাগতম SMS যায় না।'))
            ->action(function (array $data, Action $action) {
                $names = fn (string $list, $id) => static::opts($list)[(string) $id] ?? null;
                $form = $data + [
                    'server_name' => $names('ServerId', $data['server_id'] ?? null),
                    'package_name' => $names('PackageId', $data['package_id'] ?? null),
                    'status_name' => $names('Status', $data['status_id'] ?? null),
                ];
                $form['joined'] = Carbon::parse($data['joined'])->toDateString();
                try {
                    $r = Engine::newConnection(Filament::getTenant()->getKey(), $form);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(__('পাঠানো হয়নি'))->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }
                $status = $r['status'] ?? 'failed';
                Notification::make()->{$status === 'done' ? 'success' : ($status === 'failed' ? 'danger' : 'warning')}()
                    ->title(__(DeskConnection::STATUSES[$status][0] ?? $status))
                    ->body(trim(($r['customer_code'] ?? null ? __('কাস্টমার ID :c, ', ['c' => $r['customer_code']]) : '')
                        .(($r['secret_profile'] ?? null) ? __('secret :p, :s', ['p' => $r['secret_profile'],
                            's' => ($r['secret_disabled'] ?? false) ? __('বন্ধ') : __('চালু')]) : '')
                        .(($r['error'] ?? null) ? ' '.$r['error'] : '')) ?: null)
                    ->actions(($b = static::customer($r)) ? [Action::make('open')->label(__('কাস্টমার দেখুন'))->button()
                        ->url(CustomerResource::getUrl('view', ['record' => $b]))] : [])
                    ->persistent()->send();
            });
    }
}
