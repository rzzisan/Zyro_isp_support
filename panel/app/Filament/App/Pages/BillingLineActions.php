<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskLineAction;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use RuntimeException;

/**
 * লাইন বন্ধ/চালু (billing phase 3): the Desk turns a line off/on on the MikroTik itself and reads the router back
 * (engine/desk_line.py). Before the cutover only the test accounts in বিলিং নিয়ম. Owner only.
 */
class BillingLineActions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $slug = 'billing-line-actions';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|Htmlable
    {
        return __('লাইন বন্ধ/চালু: Desk থেকে সরাসরি MikroTik-এ');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && auth()->user()?->roleIn($company) === 'owner';
    }

    public function getSubheading(): ?string
    {
        $s = BillingSetting::forCompany(Filament::getTenant());
        $mode = $s->line_control ?? 'off';
        $codes = implode(', ', $s->line_test_codes ?? []);

        return __('এখন: :m.', ['m' => __(DeskLineAction::CONTROL[$mode] ?? $mode)])
            .($mode === 'test' ? ' '.__('পরীক্ষার অ্যাকাউন্ট: :c।', ['c' => $codes !== '' ? $codes : '—']) : '')
            .' '.__('প্রতিটা কাজের পর রাউটার আবার পড়ে প্রোফাইল আর সেশন মেলানো হয়, না মিললে বিলিং নিয়মের সংখ্যক বার আবার চেষ্টা। ব্যর্থ হলে মালিক/অ্যাডমিন ব্রাউজারে নোটিফিকেশন পান। বিলিং-এ কিছু লেখা হয় না।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')->label(__('লাইন বন্ধ/চালু করুন'))->icon(Heroicon::OutlinedSignal)
                ->schema([
                    TextInput::make('customer')->label(__('কাস্টমার ID বা PPPoE ID'))->required()->maxLength(100),
                    Select::make('action')->label(__('কাজ'))->required()->selectablePlaceholder(false)->default('disable')
                        ->options(collect(DeskLineAction::ACTIONS)->map(fn ($a) => __($a[0]))->all()),
                    TextInput::make('note')->label(__('কারণ / নোট'))->maxLength(500),
                ])
                ->modalDescription(__('রাউটারে সাথে সাথে বদলাবে, কাস্টমারের সংযোগ একবার কেটে আবার উঠবে। বিলিং-এ কিছু লেখা হয় না, তাই বিলিং পরে নিজের অবস্থা আবার বসাতে পারে।'))
                ->action(function (array $data) {
                    try {
                        $r = Engine::deskLine(Filament::getTenant()->getKey(), $data['action'], ['customer' => trim($data['customer'])],
                            'manual', $data['note'] ?? null);
                        $ok = ($r['status'] ?? null) === 'done';
                        Notification::make()->{$ok ? 'success' : 'danger'}()
                            ->title($ok ? __('হয়েছে, রাউটারে যাচাই মিলেছে') : __('হয়নি'))
                            ->body(__('প্রোফাইল :a → :b, :k সেশন কাটা, :n বার চেষ্টা', ['a' => $r['profile_before'] ?? '—',
                                'b' => $r['profile_after'] ?? '—', 'k' => $r['sessions_killed'] ?? 0, 'n' => $r['attempts'] ?? 0])
                                .(($r['error'] ?? null) ? '। '.$r['error'] : ''))
                            ->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('শুরু হয়নি'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $label = fn (array $map, $state) => __($map[$state][0] ?? (string) $state);

        return $table
            ->query(DeskLineAction::query()->with('user')->where('company_id', Filament::getTenant()->getKey()))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label(__('কখন'))->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M, g:i A'))
                    ->description(fn (DeskLineAction $r) => $r->user?->name ?? $r->actor),
                TextColumn::make('customer_code')->label('ID')
                    ->url(fn (DeskLineAction $r) => ($b = BillingCustomer::where('company_id', $r->company_id)->where('customer_id', $r->customer_code)->first())
                        ? CustomerResource::getUrl('view', ['record' => $b]) : null)
                    ->description(fn (DeskLineAction $r) => $r->username),
                TextColumn::make('action')->label(__('কাজ'))->badge()
                    ->formatStateUsing(fn ($state) => $label(DeskLineAction::ACTIONS, $state))
                    ->color(fn ($state) => DeskLineAction::ACTIONS[$state][1] ?? 'gray')
                    ->description(fn (DeskLineAction $r) => __(DeskLineAction::REASONS[$r->reason] ?? $r->reason).($r->note ? ': '.$r->note : '')),
                TextColumn::make('router_name')->label(__('রাউটার'))->placeholder('—'),
                TextColumn::make('profile_before')->label(__('প্রোফাইল আগে → পরে'))->placeholder('—')
                    ->formatStateUsing(fn ($state, DeskLineAction $r) => in_array($r->action, ['deactivate', 'activate'], true)
                        ? __('secret :a → :b', ['a' => $r->disabled_before ? __('বন্ধ') : __('চালু'), 'b' => $r->disabled_after === null ? '—' : ($r->disabled_after ? __('বন্ধ') : __('চালু'))])
                        : ($state ?? '—').' → '.($r->profile_after ?? '—')),
                TextColumn::make('sessions_killed')->label(__('সেশন'))
                    ->formatStateUsing(fn ($state, DeskLineAction $r) => __(':k কাটা', ['k' => $state]))
                    ->description(fn (DeskLineAction $r) => $r->session_after ? __('নতুন সেশন :u', ['u' => $r->session_after]) : __('এখন অফলাইন')),
                TextColumn::make('status')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn ($state) => $label(DeskLineAction::STATUSES, $state))
                    ->color(fn ($state) => DeskLineAction::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskLineAction $r) => trim(($r->attempts > 1 ? __(':n বার চেষ্টা', ['n' => $r->attempts]).' ' : '').($r->error ?? '')) ?: null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('ফল'))->options(collect(DeskLineAction::STATUSES)->map(fn ($r) => __($r[0]))->all()),
                SelectFilter::make('action')->label(__('কাজ'))->options(collect(DeskLineAction::ACTIONS)->map(fn ($r) => __($r[0]))->all()),
            ]);
    }
}
