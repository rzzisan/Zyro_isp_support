<?php

namespace App\Filament\App\Resources\Conversations\Widgets;

use App\Filament\App\TicketActions;
use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

/**
 * Inbox details column: the chat customer's line now (MikroTik, OLT/ONU) and their billing tickets with Solve.
 * Loads after the chat and does not poll, so a slow router never holds up the conversation.
 */
class CustomerLine extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.app.inbox-line';

    protected static bool $isDiscovered = false;

    public string $customerId = '';

    public bool $fresh = false;

    public function refresh(): void
    {
        $this->fresh = true;
    }

    /** A ticket opened from the chat header shows here without a reload. */
    #[On('inbox-tickets-changed')]
    public function ticketsChanged(): void {}

    private function company(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    public function customer(): ?BillingCustomer
    {
        return $this->customerId === '' ? null : BillingCustomer::where('company_id', $this->company())->whereNull('gone_at')
            ->where('customer_id', $this->customerId)->orderBy('is_left')->first();
    }

    /** The same Solve as the ticket details (online check, blocked lines refused, ticket-solved SMS), for one ticket of this customer. */
    public function solveAction(): Action
    {
        return TicketActions::solve()->size('xs')
            ->visible(fn (?BillingTicket $record) => (bool) $record?->isOpen())
            ->record(fn (array $arguments) => BillingTicket::where('company_id', $this->company())
                ->where('customer_id', $this->customerId)->find($arguments['ticket'] ?? 0));
    }

    protected function getViewData(): array
    {
        $customer = $this->customer();
        [$info, $error] = $customer ? TicketActions::liveInfo((int) $customer->header_id, $this->fresh) : [null, null];
        $this->fresh = false;

        return [
            'customer' => $customer,
            'info' => $info,
            'error' => $error,
            'tickets' => $this->customerId === '' ? collect()
                : BillingTicket::where('company_id', $this->company())->where('customer_id', $this->customerId)
                    ->orderByRaw("state IN ('pending', 'processing') DESC")->latest('opened_at')->limit(5)->get(),
        ];
    }
}
