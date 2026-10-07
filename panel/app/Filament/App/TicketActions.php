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

    /** "header_id|customer_id|username|mobile" so the choice carries what the ticket needs. */
    private static function customerKey(array $c): string
    {
        return implode('|', [$c['header_id'], $c['customer_id'], $c['username'] ?? '', $c['mobile'] ?? '']);
    }

    private static function customerLabel(array $c): string
    {
        return trim('ID '.$c['customer_id'].' · '.$c['name'].' · '.($c['mobile'] ?? '').($c['zone'] ? ' · '.$c['zone'] : ''), ' ·');
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
                        $c = collect(Engine::customers(static::company(), $customerId))->firstWhere('customer_id', $customerId);
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
                    ->getSearchResultsUsing(fn (string $search) => collect(Engine::customers(static::company(), $search))
                        ->mapWithKeys(fn ($c) => [static::customerKey($c) => static::customerLabel($c)])->all())
                    ->getOptionLabelUsing(fn ($value) => 'ID '.(explode('|', (string) $value)[1] ?? '').' · '.(explode('|', (string) $value)[2] ?? ''))
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('mobile', explode('|', (string) $state)[3] ?? null)),
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
