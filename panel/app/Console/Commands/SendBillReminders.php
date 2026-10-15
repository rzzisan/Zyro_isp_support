<?php

namespace App\Console\Commands;

use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\BillReminders;
use Illuminate\Console\Command;

/** Hourly (zyro-sms-reminder.timer): queue the bill reminder SMS that are due now and wake the engine to send them. */
class SendBillReminders extends Command
{
    protected $signature = 'sms:bill-reminders {--company= : only this company id}';

    protected $description = 'Queue bill reminder SMS (N days before the expire date) through the Desk SMS gateway';

    public function handle(): int
    {
        $companies = SmsSetting::where('reminder_sms', true)
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))->pluck('company_id');
        foreach ($companies as $companyId) {
            $queued = BillReminders::run((int) $companyId);
            if (array_sum($queued) > 0) {
                Engine::kickSms((int) $companyId);
            }
            $this->line("company {$companyId}: ".($queued ? json_encode($queued) : 'nothing due'));
        }

        return self::SUCCESS;
    }
}
