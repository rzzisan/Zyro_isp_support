<?php

namespace App\Console\Commands;

use App\Models\VoiceReminderSetting;
use App\Support\VoiceReminders;
use Illuminate\Console\Command;

/** Every 5 minutes (zyro-voice-reminder.timer): plan, retry and queue the voice reminder calls of every company that turned them on. */
class RunVoiceReminders extends Command
{
    protected $signature = 'voice:reminders {--company= : only this company id}';

    protected $description = 'Plan and queue automatic voice reminder calls (bill reminder, line off)';

    public function handle(): int
    {
        $companies = VoiceReminderSetting::where('enabled', true)
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))->pluck('company_id');
        foreach ($companies as $companyId) {
            $out = VoiceReminders::run((int) $companyId);
            $this->line("company {$companyId}: ".json_encode($out));
        }

        return self::SUCCESS;
    }
}
