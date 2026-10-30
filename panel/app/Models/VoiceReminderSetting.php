<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's automatic voice reminder calls (ভয়েস রিমাইন্ডার → সেটিংস); run by App\Support\VoiceReminders. */
class VoiceReminderSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'test_mode' => 'boolean', 'reminder_on' => 'boolean', 'due_only' => 'boolean',
            'line_off_on' => 'boolean', 'skip_jumma' => 'boolean', 'minute_rate' => 'float'];
    }

    public static function forCompany(Company|int $company): self
    {
        $id = $company instanceof Company ? $company->id : $company;
        $s = static::firstOrNew(['company_id' => $id]);
        if (! $s->exists) {   // the database defaults, so a page shows them before the first save
            $s->forceFill(['enabled' => false, 'test_mode' => true, 'test_daily_max' => 3, 'reminder_on' => true, 'reminder_days' => '2,0',
                'due_only' => true, 'line_off_on' => true, 'line_off_delay' => 60, 'line_off_days' => 2, 'window_from' => '10:00',
                'window_to' => '20:00', 'skip_jumma' => true, 'max_calls' => 1, 'retry_minutes' => 120, 'max_tries' => 2, 'minute_rate' => 0.5]);
            $flows = VoiceFlow::where('company_id', $id)->orderBy('sort')->get(['id', 'kind']);
            $s->reminder_flow_id = $flows->firstWhere('kind', 'reminder')?->id;
            $s->line_off_flow_id = $flows->firstWhere('kind', 'line_off')?->id;
        }

        return $s;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
