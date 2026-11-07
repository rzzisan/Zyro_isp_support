<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskConnection;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * নতুন সংযোগ (billing phase 5, plan 2.4খ): the Desk's new connection form. Saving sends the customer to billing
 * (POST /Customer/Add with the member's billing login; billing makes the PPP secret), then the engine reads the
 * router back (engine/desk_connection.py). Guarded by বিলিং নিয়ম → connection_control (off / test / all).
 * Required fields on the first tab, the rest on the second (Zisan, 5 Nov). Owner and admins.
 */
class BillingNewConnections extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $slug = 'billing-new-connections';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|Htmlable
    {
        return __('নতুন সংযোগ (বিলিং-এও তৈরি হয়)');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && in_array(auth()->user()?->roleIn($company), ['owner', 'admin'], true);
    }

    public function getSubheading(): ?string
    {
        $s = BillingSetting::forCompany(Filament::getTenant());
        $mode = $s->connection_control ?? 'off';

        return __('এখন: :m.', ['m' => __(DeskConnection::CONTROL[$mode] ?? $mode)])
            .($mode === 'test' ? ' '.__('পরীক্ষার username শুরু: :p।', ['p' => $s->connection_test_prefix ?: '—']) : '')
            .' '.__('সেভ করলে কাস্টমার বিলিং-এ তৈরি হয়, বিলিং রাউটারে PPP secret বানায়, তারপর Desk রাউটার পড়ে profile আর চালু/বন্ধ মেলায়। PPPoE পাসওয়ার্ড Desk-এ রাখা হয় না, শুধু ফর্মে একবার দেখায়।');
    }

    /** billing's fixed lists for the form (Server, Zone, Package, ...), kept 10 minutes. */
    protected function lists(): array
    {
        $cid = Filament::getTenant()->getKey();

        return Cache::remember("new-connection-lists:{$cid}", 600, fn () => Engine::newConnectionList($cid, 'options'));
    }

    /** [id => name] for one of billing's selects. */
    protected function opts(string $name): array
    {
        return collect($this->lists()[$name] ?? [])->mapWithKeys(fn ($o) => [(string) $o['id'] => $o['name']])->all();
    }

    /** A dependent list (profiles of a Server, sub zones of a Zone, boxes of a Sub Zone), kept 10 minutes. */
    protected function remote(string $kind, $id): array
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

    protected static function newPassword(): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 10))->map(fn () => $chars[random_int(0, strlen($chars) - 1)])->implode('');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rules')->label(__('বিলিং নিয়ম'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->visible(fn () => BillingRules::canAccess())->url(fn () => BillingRules::getUrl()),
            Action::make('create')->label(__('নতুন সংযোগ'))->icon(Heroicon::OutlinedUserPlus)
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
                        'status_id' => array_search('Active', $this->opts('Status'), true) ?: null,
                        'connection_type_id' => array_search('Fiber', $this->opts('ConnectionTypeId'), true) ?: null,
                        'customer_type_id' => array_search('Home', $this->opts('CustomerTypeId'), true) ?: null,
                    ];
                })
                ->schema([
                    Tabs::make()->tabs([
                        Tab::make(__('মূল তথ্য'))->columns(2)->schema([
                            TextInput::make('name')->label(__('নাম'))->required()->maxLength(150),
                            TextInput::make('mobile')->label(__('মোবাইল'))->required()->tel()->regex('/^(\+?88)?01\d{9}$/')
                                ->helperText(__('01XXXXXXXXX')),
                            Select::make('zone_id')->label(__('Zone'))->required()->options(fn () => $this->opts('ZoneId'))->live()
                                ->afterStateUpdated(fn ($set) => $set('sub_zone_id', null)),
                            Select::make('sub_zone_id')->label(__('Sub Zone'))->placeholder(__('ঐচ্ছিক'))->options(fn ($get) => $this->remote('subzones', $get('zone_id')))->live()
                                ->afterStateUpdated(fn ($set) => $set('box_id', null)),
                            Select::make('box_id')->label(__('Box'))->placeholder(__('ঐচ্ছিক'))->options(fn ($get) => $this->remote('boxes', $get('sub_zone_id'))),
                            Select::make('server_id')->label(__('রাউটার (Server)'))->required()->options(fn () => $this->opts('ServerId'))->live()
                                ->afterStateUpdated(fn ($set) => $set('profile', null)),
                            Select::make('package_id')->label(__('প্যাকেজ'))->required()->searchable()->options(fn () => $this->opts('PackageId'))->live()
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
                                ->options(fn ($get) => $this->remote('profiles', $get('server_id')))
                                ->helperText(__('আগে রাউটার বাছুন')),
                            TextInput::make('monthly_bill')->label(__('মাসিক বিল'))->numeric()->minValue(0)->prefix('৳')
                                ->required(fn ($get) => ! $this->noBill($get('status_id'))),
                            Select::make('status_id')->label(__('Status'))->required()->options(fn () => $this->opts('Status'))->live(),
                            Select::make('connection_type_id')->label(__('Connection Type'))->required()->options(fn () => $this->opts('ConnectionTypeId')),
                            Select::make('customer_type_id')->label(__('Customer Type'))->required()->options(fn () => $this->opts('CustomerTypeId')),
                            TextInput::make('username')->label(__('PPPoE UserName'))->required()->maxLength(40)
                                ->regex('/^[A-Za-z0-9._@-]{3,40}$/')->live(onBlur: true)
                                ->helperText(fn ($state) => $this->usernameNote($state)),
                            TextInput::make('password')->label(__('PPPoE Password'))->required()->minLength(6)->maxLength(40)
                                ->helperText(__('কাস্টমারকে দিন; Desk-এ রাখা হয় না, এই ফর্ম বন্ধ হলে আর দেখা যাবে না।')),
                            DatePicker::make('joined')->label(__('সংযোগের তারিখ'))->required()->native(false)->maxDate(now()->addDays(7)),
                            TextInput::make('bill_start')->label(__('বিল শুরুর মাস (MM/YYYY)'))->required()->regex('/^(0[1-9]|1[0-2])\/\d{4}$/'),
                            TextInput::make('expire_day')->label(__('Expire Date (দিন)'))->numeric()->integer()->minValue(1)->maxValue(28)
                                ->required(fn ($get) => ! $this->noBill($get('status_id'))),
                        ]),
                        Tab::make(__('অন্যান্য (ঐচ্ছিক)'))->columns(2)->schema([
                            TextInput::make('phone')->label(__('ফোন'))->maxLength(20),
                            TextInput::make('address')->label(__('ঠিকানা'))->maxLength(300),
                            Select::make('district_id')->label(__('জেলা'))->options(fn () => $this->opts('DistrictId')),
                            Select::make('thana_id')->label(__('থানা'))->options(fn () => $this->opts('ThanaId')),
                            Toggle::make('vip')->label(__('VIP')),
                            TextInput::make('remarks')->label(__('মন্তব্য'))->maxLength(300),
                        ]),
                    ]),
                ])
                ->modalDescription(__('সেভ করলে কাস্টমার সাথে সাথে বিলিং-এ তৈরি হবে আর রাউটারে PPP secret বসবে। স্বাগতম SMS যায় না।'))
                ->action(function (array $data, Action $action) {
                    $names = fn (string $list, $id) => $this->opts($list)[(string) $id] ?? null;
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
                        ->persistent()->send();
                }),
        ];
    }

    /** Personal / Free / Left: no bill, no expire day (plan 2.4খ). */
    protected function noBill($statusId): bool
    {
        return in_array(mb_strtolower((string) ($this->opts('Status')[(string) $statusId] ?? '')), ['personal', 'free', 'left'], true);
    }

    protected function usernameNote(?string $username): ?string
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

    public function table(Table $table): Table
    {
        return $table
            ->query(DeskConnection::query()->with('user')->where('company_id', Filament::getTenant()->getKey()))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label(__('কখন'))->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M, g:i A'))
                    ->description(fn (DeskConnection $r) => $r->user?->name ?? $r->actor),
                TextColumn::make('customer_code')->label('ID')->placeholder('—')
                    ->url(fn (DeskConnection $r) => $r->header_id && ($b = BillingCustomer::where('company_id', $r->company_id)->where('header_id', $r->header_id)->first())
                        ? CustomerResource::getUrl('view', ['record' => $b]) : null)
                    ->description(fn (DeskConnection $r) => $r->username),
                TextColumn::make('name')->label(__('নাম'))->description(fn (DeskConnection $r) => $r->mobile),
                TextColumn::make('package_name')->label(__('প্যাকেজ'))->placeholder('—')
                    ->description(fn (DeskConnection $r) => trim(($r->status_name ?? '').' · ৳'.number_format((float) $r->monthly_bill))),
                TextColumn::make('server_name')->label(__('রাউটার / secret'))->placeholder('—')
                    ->description(fn (DeskConnection $r) => $r->secret_profile
                        ? $r->secret_profile.' · '.($r->secret_disabled ? __('বন্ধ') : __('চালু')) : __('secret দেখা যায়নি')),
                TextColumn::make('status')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskConnection::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskConnection::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskConnection $r) => $r->error),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('ফল'))->options(collect(DeskConnection::STATUSES)->map(fn ($r) => __($r[0]))->all()),
            ]);
    }
}
