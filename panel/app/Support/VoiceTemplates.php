<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\BillingCustomerPayment;
use App\Models\VoiceReminderSetting;
use App\Models\VoiceTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Voice reminder messages: placeholders, the text as it is spoken (Bangla digits and month names, so Gemini TTS reads
 * "৫২০ টাকা, ৩১ অক্টোবর"), and the audio (engine/voice_tts.py makes it once per text, in storage/app/voice-audio).
 */
class VoiceTemplates
{
    /** placeholder => label, for the chips; the call fills the "call" ones, a preview uses an example for them. */
    public const PLACEHOLDERS = [
        'name' => 'নাম', 'id' => 'কাস্টমার ID', 'due' => 'বকেয়া', 'monthly' => 'মাসিক বিল', 'expire_date' => 'মেয়াদ শেষের তারিখ',
        'days_left' => 'আর কত দিন', 'package' => 'প্যাকেজ', 'zone' => 'Zone', 'line_status' => 'লাইন চালু/বন্ধ',
        'last_payment' => 'শেষ পেমেন্ট', 'company' => 'কোম্পানির নাম',
    ];

    public const CALL_PLACEHOLDERS = [
        'called_for' => 'বিকল্প নম্বরে কল হলে "[নাম]-এর সংযোগের ব্যাপারে"',
        'reconnect_message' => 'লাইন চালু করার ফল (চালু হলো / কেন হলো না), কলের সময় বসে',
        'call_reason' => 'কলের কারণ',
        'pressed' => 'কাস্টমার যা চেপেছেন',
        'open_ticket' => 'খোলা টিকেট',
        'online' => 'এখন অনলাইন কিনা',
    ];

    /** What a preview says for the placeholders only a real call knows. */
    private const EXAMPLES = [
        'called_for' => '',
        'reconnect_message' => 'আপনার লাইন চালু করে দেওয়া হলো। এই সুবিধা মাসে শুধু একবার পাওয়া যায়। বকেয়া ৫২০ টাকা তাড়াতাড়ি পরিশোধ করে দেবেন।',
        'call_reason' => 'বিল রিমাইন্ডার',
        'pressed' => 'লাইন চালু করতে চেয়েছিলেন, বকেয়া বেশি',
        'open_ticket' => 'খোলা টিকেট নেই',
        'online' => 'অনলাইন',
    ];

    private const MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];

    /** [placeholder => label] for a template type. */
    public static function placeholders(string $type = 'general'): array
    {
        $out = self::PLACEHOLDERS;
        $call = match ($type) {
            'reminder', 'line_off' => ['called_for'],
            'result' => ['reconnect_message'],
            'whisper' => ['call_reason', 'pressed', 'open_ticket', 'online', 'called_for'],
            'menu', 'hold' => [],
            default => array_keys(self::CALL_PLACEHOLDERS),
        };
        foreach ($call as $k) {
            $out[$k] = self::CALL_PLACEHOLDERS[$k];
        }

        return array_map('__', $out);
    }

    public static function digits(string $s): string
    {
        return strtr($s, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    }

    /** "৩১ অক্টোবর" */
    public static function date(Carbon $d): string
    {
        return static::digits((string) $d->day).' '.self::MONTHS[$d->month - 1];
    }

    /** The real values of one customer (Bangla digits, as spoken). */
    public static function values(int $companyId, ?BillingCustomer $c): array
    {
        $v = ['company' => Sms::companyName($companyId)];
        if (! $c) {
            return $v + ['name' => 'রহিম উদ্দিন', 'id' => '১ ২ ৩ ৪', 'due' => '৫২০', 'monthly' => '৫২০', 'expire_date' => '৩১ অক্টোবর',
                'days_left' => '২', 'package' => '১০ এমবিপিএস', 'zone' => 'উত্তর পাড়া', 'line_status' => 'চালু', 'last_payment' => '৫২০ টাকা, ১ অক্টোবর'];
        }
        $today = now()->timezone(BillReminders::TZ)->startOfDay();
        $expire = $c->bill_day ? BillReminders::expireDate((int) $c->bill_day, $today) : null;
        $pay = BillingCustomerPayment::where('company_id', $companyId)->where('customer_header_id', $c->header_id)
            ->orderByDesc('received_on')->orderByDesc('id')->first(['paid', 'received_on']);

        return $v + [
            'name' => (string) $c->name,
            // one digit at a time ("৩ ৫ ৬ ১"): the voice reads each as a word, not "তিন হাজার পাঁচশো একষট্টি"
            'id' => implode(' ', mb_str_split(static::digits(ltrim((string) $c->customer_id, '0') ?: '0'))),
            'due' => static::digits(Sms::money(max(0, (float) $c->due))),
            'monthly' => static::digits(Sms::money((float) $c->monthly_bill)),
            'expire_date' => $expire ? static::date($expire) : '',
            'days_left' => $expire ? static::digits((string) (int) $today->diffInDays($expire)) : '',
            'package' => (string) $c->package,
            'zone' => (string) $c->zone,
            'line_status' => $c->disabled ? 'বন্ধ' : 'চালু',
            'last_payment' => $pay ? static::digits(Sms::money((float) $pay->paid)).' টাকা'.($pay->received_on ? ', '.static::date(Carbon::parse($pay->received_on)) : '') : 'নেই',
        ];
    }

    /** The template text with the values in; unknown {x} stay as they are, call-only ones get $extra or an example. */
    public static function render(string $body, array $values, array $extra = []): string
    {
        $all = $extra + $values + self::EXAMPLES;

        return trim(preg_replace('/\s+/u', ' ', preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $all) ? (string) $all[$m[1]] : $m[0], $body)));
    }

    /**
     * The pieces a spliced audio is joined from (engine/voice_tts.py make_parts): the template's own words, and each
     * filled-in value apart, so "বিশ টাকা" or a name is made once and shared by every customer who has it. A word
     * stuck to a value ("{name}-এর", "{due},") goes with the value. Null when nothing is filled in (one whole audio).
     */
    public static function parts(string $body, array $values, array $extra = []): ?array
    {
        $all = $extra + $values + self::EXAMPLES;
        $out = [];   // [is value, text]
        foreach (preg_split('/(\{\w+\})/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (preg_match('/^\{(\w+)\}$/', $piece, $m) && array_key_exists($m[1], $all)) {
                $out[] = [true, (string) $all[$m[1]]];
            } elseif ($piece !== '') {
                $out[] = [false, $piece];
            }
        }
        if (! array_filter($out, fn ($p) => $p[0])) {
            return null;
        }
        $n = count($out);
        for ($i = 0; $i < $n; $i++) {
            if (! $out[$i][0]) {
                continue;
            }
            // what touches the value without a space: its suffix after it, its prefix before it
            if ($i + 1 < $n && ! $out[$i + 1][0] && preg_match('/^(\S+)(.*)$/su', $out[$i + 1][1], $m)) {
                $out[$i][1] .= $m[1];
                $out[$i + 1][1] = $m[2];
            }
            if ($i > 0 && ! $out[$i - 1][0] && preg_match('/^(.*?)(\S+)$/su', $out[$i - 1][1], $m)) {
                $out[$i][1] = $m[2].$out[$i][1];
                $out[$i - 1][1] = $m[1];
            }
        }
        $parts = [];
        $last = null;
        foreach ($out as [$isValue, $text]) {
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                continue;
            }
            // values side by side ("{name} {id}") or words side by side stay one piece
            if ($last === $isValue && $parts) {
                $parts[count($parts) - 1] .= ' '.$text;
            } else {
                $parts[] = $text;
            }
            $last = $isValue;
        }

        return count($parts) > 1 ? $parts : null;
    }

    /** Whether this company's messages with customer values are made by joining pieces (রিমাইন্ডার সেটিংস → অডিও বানানো). */
    public static function spliced(int $companyId): bool
    {
        return (VoiceReminderSetting::where('company_id', $companyId)->value('audio_mode') ?? 'splice') === 'splice';
    }

    /** The spoken text of a template and, when spliced, its pieces (null: one whole audio). */
    public static function speech(int $companyId, string $body, array $values, array $extra = []): array
    {
        return ['text' => static::render($body, $values, $extra), 'parts' => static::spliced($companyId) ? static::parts($body, $values, $extra) : null];
    }

    /** Placeholders in the text that are not known (typos): shown under the text box. */
    public static function unknown(string $body): array
    {
        preg_match_all('/\{(\w+)\}/', $body, $m);

        return array_values(array_unique(array_diff($m[1], array_keys(self::PLACEHOLDERS + self::CALL_PLACEHOLDERS))));
    }

    /** Audio of a spoken text: "{company}/{hash}" (storage/app/voice-audio/…{.mp3,.wav}). Throws with a Bangla reason. */
    public static function audio(int $companyId, string $text, string $voice, float $speed, ?array $parts = null): string
    {
        try {
            $r = Http::baseUrl(config('services.engine.url'))->timeout(120)->acceptJson()
                ->withHeaders(['x-internal-key' => (string) config('services.engine.key')])
                ->post("/internal/{$companyId}/voice/tts", array_filter(['text' => $text, 'voice' => $voice, 'speed' => $speed, 'parts' => $parts]));
        } catch (\Throwable $e) {
            throw new RuntimeException(__('অডিও বানানো গেল না: ইঞ্জিনে পৌঁছানো যায়নি'));
        }
        if (! $r->successful() || ! $r->json('file')) {
            throw new RuntimeException(__('অডিও বানানো গেল না').': '.($r->json('detail') ?? 'HTTP '.$r->status()));
        }

        return (string) $r->json('file');
    }

    /**
     * Several audios at once, $concurrency requests at a time: [key => ['text', 'voice', 'speed', 'parts'?]] → [key => file]
     * (the ones that failed are left out; package() then asks for them one by one).
     */
    public static function audioMany(int $companyId, array $items, int $concurrency = 4): array
    {
        $out = [];
        foreach (array_chunk($items, max(1, $concurrency), true) as $chunk) {
            try {
                $res = Http::pool(fn ($pool) => array_map(fn ($k) => $pool->as((string) $k)->baseUrl(config('services.engine.url'))
                    ->timeout(120)->acceptJson()->withHeaders(['x-internal-key' => (string) config('services.engine.key')])
                    ->post("/internal/{$companyId}/voice/tts", array_filter(['text' => $chunk[$k]['text'], 'voice' => $chunk[$k]['voice'],
                        'speed' => $chunk[$k]['speed'], 'parts' => $chunk[$k]['parts'] ?? null])),
                    array_keys($chunk)));
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($res as $k => $r) {
                if ($r instanceof \Illuminate\Http\Client\Response && $r->successful() && $r->json('file')) {
                    $out[$k] = (string) $r->json('file');
                }
            }
        }

        return $out;
    }

    /** Browser URL of a made audio (mp3) or of a template's uploaded recording. */
    public static function url(string $companySlug, string $file): string
    {
        return route('voice.audio', ['company' => $companySlug, 'file' => str_replace('/', '-', $file)]);
    }

    public static function uploadUrl(string $companySlug, VoiceTemplate $t): string
    {
        return route('voice.upload', ['company' => $companySlug, 'template' => $t->id, 'v' => substr(md5((string) $t->audio_path), 0, 8)]);
    }
}
