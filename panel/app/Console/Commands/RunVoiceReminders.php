<?php

namespace App\Console\Commands;

use App\Models\VoiceReminderSetting;
use App\Support\VoiceReminders;
use Illuminate\Console\Command;

/**
 * Every minute (zyro-voice-reminder.timer, `--for=55`): plan, retry and queue the voice reminder calls of every company
 * that turned them on, then keep queueing for the rest of the minute every few seconds, so the next call goes as soon as
 * a line is free (one call after another, up to "একসাথে কয়টা কল").
 */
class RunVoiceReminders extends Command
{
    protected $signature = 'voice:reminders {--company= : only this company id} {--for=0 : keep queueing for this many seconds}';

    /** Seconds between the quick passes. */
    public const STEP = 5;

    protected $description = 'Plan and queue automatic voice reminder calls (bill reminder, line off)';

    public function handle(): int
    {
        $until = microtime(true) + max(0, (int) $this->option('for'));
        $full = true;
        do {
            // turned off (or settings changed) a moment ago: the quick passes follow that too
            // (also the ones with only "লাইন বন্ধ কাস্টমার নিজে কল দিলে" on: its messages are made ahead here)
            $companies = VoiceReminderSetting::where(fn ($q) => $q->where('enabled', true)->orWhere('inbound_on', true))
                ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))->pluck('company_id');
            foreach ($companies as $companyId) {
                $out = array_filter(VoiceReminders::run((int) $companyId, null, $full));
                if ($full || $out) {
                    $this->line(now()->format('H:i:s')." company {$companyId}: ".json_encode($out));
                }
            }
            $full = false;
            if (microtime(true) + static::STEP > $until) {
                break;
            }
            sleep(static::STEP);
        } while (true);

        return self::SUCCESS;
    }
}
