<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\TicketActions;
use App\Models\BillingCustomer;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
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
            Action::make('live')->label('লাইভ অবস্থা')->icon(Heroicon::OutlinedSignal)
                ->modalHeading(fn () => "{$c->name} (ID {$c->customer_id}): এখনকার অবস্থা")
                ->modalSubmitAction(false)->modalCancelActionLabel('বন্ধ করুন')
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
            Action::make('password')->label('PPPoE পাসওয়ার্ড')->icon(Heroicon::OutlinedKey)->color('gray')
                ->visible(fn () => CustomerResource::managers() && filled($c->getRawOriginal('pppoe_password')))
                ->requiresConfirmation()
                ->modalDescription('পাসওয়ার্ড দেখলে কে কখন দেখেছেন তা লগে থাকবে।')
                ->modalSubmitActionLabel('দেখান')
                ->action(function () use ($c) {
                    DB::table('customer_password_views')->insert(['company_id' => $c->company_id, 'user_id' => auth()->id(),
                        'billing_customer_id' => $c->id, 'customer_id' => $c->customer_id, 'viewed_at' => now()]);
                    $this->replaceMountedAction('showPassword');
                }),
            TicketActions::newTicket($c->customer_id)->label('টিকিট খুলুন')->color('gray'),
            Action::make('chat')->label('চ্যাট')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                ->visible((bool) $chat)
                ->url(fn () => $chat ? ConversationResource::getUrl('view', ['record' => $chat]) : null),
        ];
    }

    public function showPasswordAction(): Action
    {
        $c = $this->customer();

        return Action::make('showPassword')
            ->modalHeading("{$c->username}: PPPoE পাসওয়ার্ড")
            ->modalContent(fn () => view('filament.app.customers.password', ['password' => $c->pppoe_password]))
            ->modalSubmitAction(false)->modalCancelActionLabel('বন্ধ করুন')
            ->visible(fn () => CustomerResource::managers());
    }
}
