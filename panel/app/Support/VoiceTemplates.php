<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\BillingCustomerPayment;
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
        'reconnect_until' => 'লাইন চালু হলে কবে পর্যন্ত',
        'reconnect_reason' => 'লাইন চালু না হওয়ার কারণ',
        'call_reason' => 'কলের কারণ',
        'pressed' => 'কাস্টমার যা চেপেছেন',
        'open_ticket' => 'খোলা টিকেট',
        'online' => 'এখন অনলাইন কিনা',
    ];

    /** What a preview says for the placeholders only a real call knows. */
    private const EXAMPLES = [
        'called_for' => '',
        'reconnect_until' => '৫ নভেম্বর',
        'reconnect_reason' => 'আপনার বকেয়া এক মাসের বিলের বেশি।',
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
            'result' => ['reconnect_until', 'reconnect_reason'],
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
            return $v + ['name' => 'রহিম উদ্দিন', 'id' => '১২৩৪', 'due' => '৫২০', 'monthly' => '৫২০', 'expire_date' => '৩১ অক্টোবর',
                'days_left' => '২', 'package' => '১০ এমবিপিএস', 'zone' => 'উত্তর পাড়া', 'line_status' => 'চালু', 'last_payment' => '৫২০ টাকা, ১ অক্টোবর'];
        }
        $today = now()->timezone(BillReminders::TZ)->startOfDay();
        $expire = $c->bill_day ? BillReminders::expireDate((int) $c->bill_day, $today) : null;
        $pay = BillingCustomerPayment::where('company_id', $companyId)->where('customer_header_id', $c->header_id)
            ->orderByDesc('received_on')->orderByDesc('id')->first(['paid', 'received_on']);

        return $v + [
            'name' => (string) $c->name,
            'id' => static::digits(ltrim((string) $c->customer_id, '0') ?: '0'),
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

    /** Placeholders in the text that are not known (typos): shown under the text box. */
    public static function unknown(string $body): array
    {
        preg_match_all('/\{(\w+)\}/', $body, $m);

        return array_values(array_unique(array_diff($m[1], array_keys(self::PLACEHOLDERS + self::CALL_PLACEHOLDERS))));
    }

    /** Audio of a spoken text: "{company}/{hash}" (storage/app/voice-audio/…{.mp3,.wav}). Throws with a Bangla reason. */
    public static function audio(int $companyId, string $text, string $voice, float $speed): string
    {
        try {
            $r = Http::baseUrl(config('services.engine.url'))->timeout(120)->acceptJson()
                ->withHeaders(['x-internal-key' => (string) config('services.engine.key')])
                ->post("/internal/{$companyId}/voice/tts", ['text' => $text, 'voice' => $voice, 'speed' => $speed]);
        } catch (\Throwable $e) {
            throw new RuntimeException(__('অডিও বানানো গেল না: ইঞ্জিনে পৌঁছানো যায়নি'));
        }
        if (! $r->successful() || ! $r->json('file')) {
            throw new RuntimeException(__('অডিও বানানো গেল না').': '.($r->json('detail') ?? 'HTTP '.$r->status()));
        }

        return (string) $r->json('file');
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
