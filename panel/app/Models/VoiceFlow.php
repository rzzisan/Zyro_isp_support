<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A voice reminder call flow, run by the voice bot (engine/voice_ivr.py). The call starts at the first step.
 *
 * steps (JSON list), each: {name, templates: [voice_template ids, played in order], wait: seconds for a key,
 *   repeats: plays when no key is pressed, no_input_template_id, invalid_template_id,
 *   keys: [{digit, action, say (template id), say_fail, sms_template_id, problem, next (step name), next_fail}]}
 * actions: replay | goto | sms | reconnect | staff | ticket | optout | end.
 * hold (JSON): while staff are rung: {start_template_id, repeat_template_id, every (s), sound ring|music|silence,
 *   max_wait (s), after voicemail|callback|end, voicemail_template_id, callback_template_id, ring all|one|least,
 *   ring_each (s), whisper: [WHISPER keys]}.
 */
class VoiceFlow extends Model
{
    public const KINDS = ['reminder' => 'বিল রিমাইন্ডার', 'line_off' => 'লাইন বন্ধ', 'general' => 'সাধারণ'];

    public const ACTIONS = [
        'sms' => 'SMS পাঠাও',
        'staff' => 'স্টাফের সাথে কথা (Desk-এ রিং)',
        'reconnect' => 'লাইন চালু করো (মাসে একবারের নিয়মে)',
        'ticket' => 'টিকেট খোলো',
        'replay' => 'আবার বাজাও',
        'goto' => 'অন্য ধাপে যাও',
        'optout' => 'আর কল নয়',
        'end' => 'কল শেষ',
    ];

    public const SOUNDS = ['ring' => 'রিং টোন', 'music' => 'মিউজিক', 'silence' => 'চুপ'];

    public const AFTER = ['voicemail' => 'কাস্টমার ভয়েস মেসেজ রাখবেন', 'callback' => 'কল-ব্যাক তালিকায় তুলে কল শেষ', 'end' => 'বিদায় বলে কল শেষ'];

    /** Whose app rings when the customer asks for staff (on duty, "কল নিচ্ছি" on, not on another call). */
    public const RINGS = ['all' => 'সবার একসাথে (যে আগে ধরে)', 'one' => 'একজন একজন করে পালাক্রমে', 'least' => 'আজ যিনি কম কল ধরেছেন তার আগে'];

    /** What the agent's screen shows about the customer before taking the call (the ring note). */
    public const WHISPER = ['flow' => 'কোন কল থেকে (ফ্লোর নাম)', 'due' => 'বকেয়া', 'line' => 'লাইন চালু/বন্ধ', 'expire' => 'মেয়াদ',
        'pressed' => 'কলে কী করেছেন (SMS নিয়েছেন, লাইন চালুর চেষ্টা)'];

    public const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '*', '#'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'hold' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Every voice template id the flow can play (for the audio of a call). */
    public function templateIds(): array
    {
        $ids = [$this->goodbye_template_id];
        foreach ($this->steps ?? [] as $s) {
            array_push($ids, ...array_map('intval', $s['templates'] ?? []));
            $ids[] = $s['no_input_template_id'] ?? null;
            $ids[] = $s['invalid_template_id'] ?? null;
            foreach ($s['keys'] ?? [] as $k) {
                $ids[] = $k['say'] ?? null;
                $ids[] = $k['say_fail'] ?? null;
            }
        }
        foreach (['start_template_id', 'repeat_template_id', 'voicemail_template_id', 'callback_template_id'] as $f) {
            $ids[] = $this->hold[$f] ?? null;
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public function smsTemplateIds(): array
    {
        $ids = [];
        foreach ($this->steps ?? [] as $s) {
            foreach ($s['keys'] ?? [] as $k) {
                if (($k['action'] ?? '') === 'sms' && ! empty($k['sms_template_id'])) {
                    $ids[] = (int) $k['sms_template_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** Problems in the steps: names, keys, where a key leads. Empty = fine. */
    public static function problems(array $steps): array
    {
        $out = [];
        $names = array_map(fn ($s) => trim((string) ($s['name'] ?? '')), $steps);
        if (! $steps) {
            return [__('অন্তত একটা ধাপ লাগবে')];
        }
        foreach (array_count_values(array_filter($names)) as $n => $c) {
            if ($c > 1) {
                $out[] = __('":name" নামে দুটো ধাপ', ['name' => $n]);
            }
        }
        foreach ($steps as $i => $s) {
            $name = $names[$i] ?: __('ধাপ :n', ['n' => $i + 1]);
            if ($names[$i] === '') {
                $out[] = __(':name: নাম দিন', ['name' => $name]);
            }
            if (empty($s['templates'])) {
                $out[] = __(':name: কোন মেসেজ বাজবে বাছুন', ['name' => $name]);
            }
            $digits = [];
            foreach ($s['keys'] ?? [] as $k) {
                $d = (string) ($k['digit'] ?? '');
                if (in_array($d, $digits, true)) {
                    $out[] = __(':name: বাটন :d দুবার', ['name' => $name, 'd' => $d]);
                }
                $digits[] = $d;
                foreach (['next', 'next_fail'] as $f) {
                    if (! empty($k[$f]) && ! in_array($k[$f], $names, true)) {
                        $out[] = __(':name: বাটন :d যে ধাপে যায় (":to") সেটা নেই', ['name' => $name, 'd' => $d, 'to' => $k[$f]]);
                    }
                }
                if (($k['action'] ?? '') === 'goto' && empty($k['next'])) {
                    $out[] = __(':name: বাটন :d কোন ধাপে যাবে বাছুন', ['name' => $name, 'd' => $d]);
                }
                if (($k['action'] ?? '') === 'sms' && empty($k['sms_template_id'])) {
                    $out[] = __(':name: বাটন :d কোন SMS যাবে বাছুন', ['name' => $name, 'd' => $d]);
                }
            }
        }

        return $out;
    }

    /** The two flows (and the messages they need) every company starts with. */
    public static function seed(int $companyId): void
    {
        if (static::where('company_id', $companyId)->exists()) {
            return;
        }
        VoiceTemplate::seed($companyId);
        $t = fn (string $name, string $type = 'result', ?string $body = null) => VoiceTemplate::firstOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            ['type' => $type, 'body' => $body ?? (collect(VoiceTemplate::DEFAULTS)->firstWhere(1, $name)[2] ?? $name), 'sort' => 50])->id;
        $sms = SmsTemplate::firstOrCreate(['company_id' => $companyId, 'name' => 'পেমেন্টের নিয়ম'], ['type' => 'general',
            'body' => 'প্রিয় {name}, আপনার বকেয়া {due} টাকা। বিকাশ/নগদে বিল দেওয়ার নিয়ম দেখুন: (এখানে ভিডিও লিংক দিন)। আইডি {id}। {company}']);
        $hold = [
            'start_template_id' => $t('অপেক্ষা: শুরু', 'hold'), 'repeat_template_id' => $t('অপেক্ষা: মাঝে মাঝে', 'hold'),
            'every' => 25, 'sound' => 'ring', 'max_wait' => 90, 'after' => 'voicemail', 'ring' => 'all', 'ring_each' => 20,
            'whisper' => array_keys(self::WHISPER),
            'voicemail_template_id' => $t('ভয়েস মেসেজ রাখুন', 'hold', 'এই মুহূর্তে কেউ ধরতে পারছেন না। বিপ শব্দের পর আপনার কথা বলুন, শেষ হলে ফোন রেখে দিন। আমরা শুনে আপনাকে কল করব।'),
            'callback_template_id' => $t('পরে কল করব', 'hold', 'এই মুহূর্তে কেউ ধরতে পারছেন না। আমরা আপনাকে একটু পরে কল করব। ধন্যবাদ।'),
        ];
        $common = fn (array $keys) => [
            ...$keys,
            ['digit' => '1', 'action' => 'sms', 'sms_template_id' => $sms->id, 'say' => $t('SMS পাঠানো হয়েছে'), 'next' => 'মেনু'],
            ['digit' => '2', 'action' => 'staff'],
            ['digit' => '0', 'action' => 'replay'],
            ['digit' => '9', 'action' => 'optout', 'say' => $t('আর কল নয়')],
        ];
        $step = fn (string $name, array $templates, array $keys) => ['name' => $name, 'templates' => $templates, 'wait' => 7,
            'repeats' => 2, 'no_input_template_id' => $t('কোনো বাটন চাপেননি'), 'invalid_template_id' => $t('ভুল বাটন'), 'keys' => $keys];
        $reminderMenu = $t('রিমাইন্ডারের মেনু', 'menu');
        $lineMenu = $t('লাইন বন্ধের মেনু', 'menu');
        $reconnect = ['digit' => '3', 'action' => 'reconnect', 'say' => $t('লাইন চালু হয়েছে'), 'say_fail' => $t('লাইন চালু করা গেল না'), 'next' => 'মেনু', 'next_fail' => 'মেনু'];
        static::create(['company_id' => $companyId, 'name' => 'বিল রিমাইন্ডার কল', 'kind' => 'reminder', 'sort' => 0, 'hold' => $hold,
            'goodbye_template_id' => $t('বিদায়'),
            'steps' => [$step('শুরু', [$t('বিল রিমাইন্ডার', 'reminder'), $reminderMenu], $common([])), $step('মেনু', [$reminderMenu], $common([]))]]);
        static::create(['company_id' => $companyId, 'name' => 'লাইন বন্ধের কল', 'kind' => 'line_off', 'sort' => 1, 'hold' => $hold,
            'goodbye_template_id' => $t('বিদায়'),
            'steps' => [$step('শুরু', [$t('লাইন বন্ধ', 'line_off'), $lineMenu], $common([$reconnect])), $step('মেনু', [$lineMenu], $common([$reconnect]))]]);
    }
}
