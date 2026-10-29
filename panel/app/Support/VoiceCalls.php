<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\SipSetting;
use App\Models\SmsTemplate;
use App\Models\VoiceFlow;
use App\Models\VoiceReminderCall;
use App\Models\VoiceTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A voice reminder call is built here and run by the voice bot (engine/voice_ivr.py): the flow, every message's audio
 * made ahead (so the call itself needs no AI), the SMS texts a key may send, and who the customer is. The few
 * messages only the call can finish ({reconnect_message}) go as text and are read out when they are needed.
 */
class VoiceCalls
{
    /** Placeholders the engine fills during the call. */
    public const CALL_TIME = ['reconnect_message'];

    public static function package(VoiceFlow $flow, ?BillingCustomer $c, array $extra = []): array
    {
        $company = $flow->company_id;
        $values = VoiceTemplates::values($company, $c);
        $keep = array_combine(array_map(fn ($k) => $k, self::CALL_TIME), array_map(fn ($k) => '{'.$k.'}', self::CALL_TIME));
        $audio = [];
        foreach (VoiceTemplate::where('company_id', $company)->whereIn('id', $flow->templateIds())->get() as $t) {
            if ($t->source === 'upload') {
                if ($t->file()) {
                    $audio[$t->id] = ['upload' => $t->audio_path];
                }

                continue;
            }
            $text = VoiceTemplates::render((string) $t->body, $values, $extra + $keep);
            if ($text === '') {
                continue;
            }
            $audio[$t->id] = preg_match('/\{('.implode('|', self::CALL_TIME).')\}/', $text)
                ? ['text' => $text, 'voice' => $t->voice, 'speed' => (float) $t->speed]
                : ['file' => VoiceTemplates::audio($company, $text, $t->voice, (float) $t->speed)];
        }
        $sms = [];
        foreach (SmsTemplate::where('company_id', $company)->whereIn('id', $flow->smsTemplateIds())->get() as $s) {
            $text = Sms::render($s->body, $c, ['company' => Sms::companyName($company)]);
            $p = Sms::parts($text);
            $sms[$s->id] = ['text' => $text, 'parts' => $p['parts'], 'unicode' => $p['unicode']];
        }

        return [
            'flow' => ['id' => $flow->id, 'name' => $flow->name, 'steps' => $flow->steps ?? [], 'hold' => $flow->hold ?? [],
                'goodbye_template_id' => $flow->goodbye_template_id],
            'audio' => $audio,
            'sms' => $sms,
            'customer' => $c ? ['header_id' => $c->header_id, 'customer_id' => $c->customer_id, 'name' => $c->name] : null,
            'label' => $c ? "{$c->name} (ID {$c->customer_id})" : '',
        ];
    }

    /** "আমার নম্বরে টেস্ট কল": the flow, now, to one number, with a customer's data (or the example). */
    public static function test(VoiceFlow $flow, string $number, ?BillingCustomer $c, ?int $userId): VoiceReminderCall
    {
        $n = Sms::mobile($number);
        if (! $n) {
            throw new RuntimeException(__('সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)'));
        }
        if (! SipSetting::where('company_id', $flow->company_id)->where('enabled', true)->whereNotNull('password')->exists()) {
            throw new RuntimeException(__('হটলাইন (SIP) সেটিং চালু নেই, কল করা যাবে না'));
        }
        $problems = VoiceFlow::problems($flow->steps ?? []);
        if ($problems) {
            throw new RuntimeException($problems[0]);
        }
        $call = VoiceReminderCall::create([
            'company_id' => $flow->company_id, 'voice_flow_id' => $flow->id, 'user_id' => $userId, 'kind' => 'test',
            'customer_header_id' => $c?->header_id, 'customer_id' => $c?->customer_id, 'name' => $c?->name, 'number' => $n,
            'package' => static::package($flow, $c), 'status' => 'queued', 'scheduled_at' => now(),
        ]);
        static::kick($call->id);

        return $call;
    }

    /** Tell the voice bot a call is waiting (it also looks every few seconds by itself). */
    public static function kick(int $id): void
    {
        try {
            Http::timeout(5)->get('http://127.0.0.1:8997/remind', ['id' => $id]);
        } catch (\Throwable $e) {
            Log::info('voice reminder kick: '.$e->getMessage());
        }
    }
}
