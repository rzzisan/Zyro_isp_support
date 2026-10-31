<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's automatic voice reminder calls (ভয়েস রিমাইন্ডার → সেটিংস); run by App\Support\VoiceReminders. */
class VoiceReminderSetting extends Model
{
    protected $guarded = [];

    /** audio_mode: how a message with customer values is made into audio (engine/voice_tts.py). */
    public const AUDIO_MODES = [
        'splice' => 'জোড়া লাগানো: টেমপ্লেটের লেখা একবার, নাম/টাকা/তারিখ আলাদা (খরচ অনেক কম)',
        'full' => 'পুরো লেখা প্রতি কাস্টমারের জন্য আলাদা (শুনতে বেশি স্বাভাবিক, খরচ বেশি)',
    ];

    /** tts_model: the Gemini voice model; 'auto' (or empty) = the newest first, the older one when it fails. */
    public const TTS_MODELS = [
        'auto' => 'নিজে থেকে (নতুনটা আগে, না পেলে পুরোনোটা)',
        'gemini-3.8-flash-lite-tts' => 'Gemini 3.8 Flash Lite TTS (দ্রুত, সস্তা)',
        'gemini-2.5-flash-preview-tts' => 'Gemini 2.5 Flash TTS',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'test_mode' => 'boolean', 'reminder_on' => 'boolean', 'due_only' => 'boolean',
            'line_off_on' => 'boolean', 'skip_jumma' => 'boolean', 'minute_rate' => 'float', 'alt_on' => 'boolean', 'sms_saving' => 'boolean', 'inbound_on' => 'boolean'];
    }

    public static function forCompany(Company|int $company): self
    {
        $id = $company instanceof Company ? $company->id : $company;
        $s = static::firstOrNew(['company_id' => $id]);
        if (! $s->exists) {   // the database defaults, so a page shows them before the first save
            $s->forceFill(['enabled' => false, 'test_mode' => true, 'test_daily_max' => 3, 'reminder_on' => true, 'reminder_days' => '2,0',
                'due_only' => true, 'line_off_on' => true, 'line_off_delay' => 60, 'line_off_days' => 2, 'window_from' => '10:00',
                'window_to' => '20:00', 'skip_jumma' => true, 'max_calls' => 1, 'retry_minutes' => 120, 'max_tries' => 2, 'minute_rate' => 0.5,
                'alt_on' => true, 'alt_max' => 3, 'sms_saving' => true, 'inbound_on' => false, 'inbound_delay' => 2,
                'audio_mode' => 'splice', 'tts_model' => 'auto']);
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
