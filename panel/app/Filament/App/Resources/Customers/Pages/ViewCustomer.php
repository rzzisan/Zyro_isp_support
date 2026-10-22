<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\BillActions;
use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\Customers\Widgets\CustomerHistoryStatus;
use App\Filament\App\Resources\Customers\Widgets\CustomerLiveInfo;
use App\Filament\App\SmsActions;
use App\Filament\App\WaCampaignActions;
use App\Filament\App\TicketActions;
use App\Models\BillingCustomer;
use App\Models\SipSetting;
use App\Support\Menu;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use RuntimeException;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    private function customer(): BillingCustomer
    {
        /** @var BillingCustomer */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        $c = $this->customer();
        $chat = $c->chat();

        return [
            Action::make('live')->label(__('লাইভ অবস্থা'))->icon(Heroicon::OutlinedSignal)
                ->modalHeading(fn () => "{$c->name} (ID {$c->customer_id}): ".__('এখনকার অবস্থা'))
                ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ করুন'))
                ->modalContent(function () use ($c) {
                    try {
                        $live = Engine::customerLive($c->company_id, (int) $c->header_id);
                        $error = null;
                    } catch (RuntimeException $e) {
                        $live = null;
                        $error = $e->getMessage();
                    }

                    return view('filament.app.customers.live', ['live' => $live, 'error' => $error]);
                }),
            Action::make('password')->label(__('PPPoE পাসওয়ার্ড'))->icon(Heroicon::OutlinedKey)->color('gray')
                ->visible(fn () => CustomerResource::managers() && filled($c->getRawOriginal('pppoe_password')))
                ->requiresConfirmation()
                ->modalDescription(__('পাসওয়ার্ড দেখলে কে কখন দেখেছেন তা লগে থাকবে।'))
                ->modalSubmitActionLabel(__('দেখান'))
                ->action(function () use ($c) {
                    DB::table('customer_password_views')->insert(['company_id' => $c->company_id, 'user_id' => auth()->id(),
                        'billing_customer_id' => $c->id, 'customer_id' => $c->customer_id, 'viewed_at' => now()]);
                    $this->replaceMountedAction('showPassword');
                }),
            // Desk softphone (public/softphone/phone.html): the customer's mobile through the hotline extension, recorded
            Action::make('call')->label(__('কল'))->icon(Heroicon::OutlinedPhone)->color('success')
                ->visible(fn () => filled($c->mobile) && Menu::can('phone') && SipSetting::live($c->company_id))
                ->url(fn () => asset('softphone/phone.html').'?'.http_build_query(['c' => Filament::getTenant()->slug,
                    'dial' => $c->mobile, 'name' => "{$c->name} (ID {$c->customer_id})"]))
                ->openUrlInNewTab(),
            BillActions::receive($c),
            TicketActions::newTicket($c->customer_id)->label(__('টিকিট খুলুন'))->color('gray'),
            SmsActions::send($c)->label('SMS')->color('gray'),
            WaCampaignActions::send($c)->label('WhatsApp')->color('gray'),
            Action::make('chat')->label(__('চ্যাট'))->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                ->visible((bool) $chat)
                ->url(fn () => $chat ? ConversationResource::getUrl('view', ['record' => $chat]) : null),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [CustomerLiveInfo::class,
            CustomerHistoryStatus::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /** The background refresh from billing finished: show the new Service / Network / Personal data. */
    #[On('customer-history-updated')]
    public function historyUpdated(): void
    {
        $this->getRecord()->refresh();
    }

    public function showPasswordAction(): Action
    {
        $c = $this->customer();

        return Action::make('showPassword')
            ->modalHeading("{$c->username}: ".__('PPPoE পাসওয়ার্ড'))
            ->modalContent(fn () => view('filament.app.customers.password', ['password' => $c->pppoe_password]))
            ->modalSubmitAction(false)->modalCancelActionLabel(__('বন্ধ করুন'))
            ->visible(fn () => CustomerResource::managers());
    }
}
