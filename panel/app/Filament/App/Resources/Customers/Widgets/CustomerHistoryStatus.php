<?php

namespace App\Filament\App\Resources\Customers\Widgets;

use App\Models\BillingCustomer;
use App\Services\Engine;
use Filament\Widgets\Widget;

/**
 * Customer page opened: asks the engine to re-read from billing whatever part of this customer is old (in the
 * background), shows "updating", and tells the tabs to re-render when it is done. The page itself never waits.
 */
class CustomerHistoryStatus extends Widget
{
    protected string $view = 'filament.app.customers.history-status';

    protected int|string|array $columnSpan = 'full';

    public ?BillingCustomer $record = null;

    public bool $running = false;

    public bool $failed = false;

    public function mount(): void
    {
        if (! $this->record) {
            return;
        }
        try {
            $this->running = (bool) (Engine::refreshCustomerHistory($this->record->company_id, (int) $this->record->header_id)['running'] ?? false);
        } catch (\Throwable $e) {
            report($e);
            $this->failed = true;
        }
    }

    public function check(): void
    {
        if (! $this->running || ! $this->record) {
            return;
        }
        try {
            $this->running = Engine::customerHistoryRunning($this->record->company_id, (int) $this->record->header_id);
        } catch (\Throwable $e) {
            $this->running = false;
        }
        if (! $this->running) {
            $this->dispatch('customer-history-updated');
        }
    }
}
