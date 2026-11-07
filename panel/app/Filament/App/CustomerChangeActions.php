<?php

namespace App\Filament\App;

use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskCustomerChange;
use App\Services\Engine;
use App\Support\Menu;
use App\Support\NewConnectionForm as F;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Billing phase 5 (plan 2.4খ): changes to an existing customer from the কাস্টমার page: package change, Left, back
 * from Left, PPPoE password. Each goes to billing's edit form and then to the router (engine/desk_change.py).
 * Members with the "কাস্টমারের প্যাকেজ/Left/পাসওয়ার্ড বদল" permission (customer_change; the owner always), only while
 * বিলিং নিয়ম → connection_control is not off ('test': only test usernames).
 */
class CustomerChangeActions
{
    public static function canChange(): bool
    {
        return Menu::can('customer_change');
    }

    private static function off(): bool
    {
        return (BillingSetting::forCompany(Filament::getTenant())->connection_control ?? 'off') === 'off';
    }

    private static function isLeft(BillingCustomer $c): bool
    {
        return (bool) $c->is_left || mb_strtolower((string) $c->status) === 'left';
    }

    private static function serverId(BillingCustomer $c): ?string
    {
        $want = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $c->server));
        foreach (F::opts('ServerId') as $id => $name) {
            if (preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) === $want) {
                return (string) $id;
            }
        }

        return null;
    }

    private static function statusId(string $name): ?string
    {
        foreach (F::opts('Status') as $id => $n) {
            if (mb_strtolower($n) === mb_strtolower($name)) {
                return (string) $id;
            }
        }

        return null;
    }

    private static function send(BillingCustomer $c, string $kind, array $params, Action $action): void
    {
        try {
            $r = Engine::customerChange($c->company_id, $kind, (int) $c->header_id, $params);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('হয়নি'))->body($e->getMessage())->persistent()->send();
            $action->halt();

            return;
        }
        $status = $r['status'] ?? 'failed';
        Notification::make()->{$status === 'done' ? 'success' : ($status === 'failed' ? 'danger' : 'warning')}()
            ->title(__(DeskCustomerChange::KINDS[$kind] ?? $kind).': '.__(DeskCustomerChange::STATUSES[$status][0] ?? $status))
            ->body(trim(($r['note'] ?? '').' '.($r['error'] ?? '')) ?: null)
            ->persistent()->send();
    }

    private static function packageFields(BillingCustomer $c): array
    {
        return [
            Select::make('package_id')->label(__('প্যাকেজ'))->required()->searchable()->options(fn () => F::opts('PackageId'))->live()
                ->afterStateUpdated(function ($state, $set) use ($c) {
                    if (! $state) {
                        return;
                    }
                    try {
                        $bill = Engine::newConnectionList($c->company_id, 'bill', (int) $state)['bill'] ?? null;
                    } catch (RuntimeException) {
                        $bill = null;
                    }
                    if ($bill !== null) {
                        $set('monthly_bill', (int) $bill);
                    }
                }),
            Select::make('profile')->label(__('MikroTik profile (Speed)'))->required()->searchable()
                ->options(fn () => F::remote('profiles', static::serverId($c))),
            TextInput::make('monthly_bill')->label(__('মাসিক বিল'))->numeric()->minValue(0)->prefix('৳')->required(),
        ];
    }

    public static function group(BillingCustomer $c): ActionGroup
    {
        $note = fn () => static::off() ? __('বিলিং নিয়মে "Desk থেকে নতুন সংযোগ" বন্ধ') : null;

        return ActionGroup::make([
            Action::make('changePackage')->label(__('প্যাকেজ বদল'))->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn () => ! static::isLeft($c))->disabled(fn () => static::off())->tooltip($note)
                ->modalHeading(fn () => __('প্যাকেজ বদল: :n (ID :id)', ['n' => $c->name, 'id' => $c->customer_id]))
                ->modalDescription(fn () => __('এখন: :p, :s, ৳:b। সেভ করলে বিলিং-এ প্যাকেজ বদলাবে আর রাউটারে secret-এর profile বদলাবে (লাইন বন্ধ থাকলে চালুর সময়)।',
                    ['p' => $c->package ?: '—', 's' => $c->speed ?: '—', 'b' => number_format((float) $c->monthly_bill)]))
                ->fillForm(fn () => ['package_id' => $c->package_id ? (string) $c->package_id : null, 'profile' => $c->speed,
                    'monthly_bill' => (int) $c->monthly_bill])
                ->schema(static::packageFields($c))
                ->action(fn (array $data, Action $action) => static::send($c, 'package', [
                    'package_id' => $data['package_id'], 'profile' => $data['profile'], 'monthly_bill' => $data['monthly_bill'],
                    'package_name' => F::opts('PackageId')[(string) $data['package_id']] ?? null, 'status_name' => $c->status,
                ], $action)),
            Action::make('makeLeft')->label(__('Left করুন'))->icon(Heroicon::OutlinedUserMinus)->color('danger')
                ->visible(fn () => ! static::isLeft($c))->disabled(fn () => static::off())->tooltip($note)
                ->requiresConfirmation()
                ->modalHeading(fn () => __('Left করবেন: :n (ID :id)?', ['n' => $c->name, 'id' => $c->customer_id]))
                ->modalDescription(__('বিলিং-এ Status Left, মাসিক বিল ০ হবে; রাউটার থেকে PPP secret মুছে যাবে আর লাইন কেটে যাবে। ফেরত আনলে secret আবার বানানো হবে।'))
                ->action(fn (Action $action) => static::send($c, 'left', ['status_id' => static::statusId('Left'), 'status_name' => 'Left'], $action)),
            Action::make('bringBack')->label(__('Left থেকে ফেরত'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('success')
                ->visible(fn () => static::isLeft($c))->disabled(fn () => static::off())->tooltip($note)
                ->modalHeading(fn () => __('ফেরত আনুন: :n (ID :id)', ['n' => $c->name, 'id' => $c->customer_id]))
                ->modalDescription(__('বিলিং-এ Status বদলাবে আর রাউটারে secret আবার তৈরি হবে (বিলিং-এ রাখা পাসওয়ার্ড দিয়ে)।'))
                ->fillForm(fn () => ['status_id' => static::statusId('Active'), 'package_id' => $c->package_id ? (string) $c->package_id : null,
                    'profile' => $c->speed, 'monthly_bill' => (int) $c->monthly_bill ?: null, 'expire_day' => $c->bill_day ?: min(now('Asia/Dhaka')->day + 2, 28)])
                ->schema([
                    Select::make('status_id')->label(__('Status'))->required()->live()
                        ->options(fn () => collect(F::opts('Status'))->reject(fn ($n) => mb_strtolower($n) === 'left')->all()),
                    ...static::packageFields($c),
                    TextInput::make('expire_day')->label(__('Expire Date (দিন)'))->numeric()->integer()->minValue(1)->maxValue(28)
                        ->required(fn ($get) => ! F::noBill($get('status_id'))),
                ])
                ->action(fn (array $data, Action $action) => static::send($c, 'back', [
                    'status_id' => $data['status_id'], 'status_name' => F::opts('Status')[(string) $data['status_id']] ?? null,
                    'package_id' => $data['package_id'], 'profile' => $data['profile'], 'monthly_bill' => $data['monthly_bill'],
                    'expire_day' => $data['expire_day'] ?? null,
                ], $action)),
            Action::make('newPassword')->label(__('PPPoE পাসওয়ার্ড বদল'))->icon(Heroicon::OutlinedKey)
                ->visible(fn () => ! static::isLeft($c))->disabled(fn () => static::off())->tooltip($note)
                ->modalHeading(fn () => __('নতুন পাসওয়ার্ড: :u', ['u' => $c->username]))
                ->modalDescription(__('বিলিং-এ আর রাউটারে পাসওয়ার্ড বদলাবে, চলমান সেশন কেটে যাবে। কাস্টমারের রাউটারেও নতুন পাসওয়ার্ড দিতে হবে।'))
                ->fillForm(fn () => ['password' => F::newPassword()])
                ->schema([TextInput::make('password')->label(__('নতুন পাসওয়ার্ড'))->required()->minLength(6)->maxLength(40)
                    ->regex('/^\S+$/')->helperText(__('কাস্টমারকে দিন; Desk-এ রাখা হয় না।'))])
                ->action(fn (array $data, Action $action) => static::send($c, 'password', ['password' => $data['password']], $action)),
        ])->label(__('বিলিং-এ বদল'))->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')->button()
            ->visible(fn () => static::canChange() && filled($c->header_id) && filled($c->username));
    }
}
