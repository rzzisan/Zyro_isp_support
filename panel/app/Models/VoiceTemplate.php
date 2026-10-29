<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message a voice reminder call plays: text read by Gemini TTS (voice + speed) or an uploaded recording. The call
 * flows pick them by id; the type only groups them and decides the placeholder chips. Placeholders:
 * App\Support\VoiceTemplates::placeholders().
 */
class VoiceTemplate extends Model
{
    public const TYPES = [
        'reminder' => 'বিল রিমাইন্ডার',
        'line_off' => 'লাইন বন্ধ',
        'menu' => 'বাটনের মেনু',
        'result' => 'বাটনের ফলাফল',
        'hold' => 'অপেক্ষা (এজেন্ট ধরা পর্যন্ত)',
        'whisper' => 'এজেন্টকে জানানো',
        'general' => 'সাধারণ',
    ];

    /** Gemini TTS voices offered (name => label). */
    public const VOICES = [
        'Kore' => 'Kore (নারী)', 'Aoede' => 'Aoede (নারী)', 'Leda' => 'Leda (নারী)', 'Zephyr' => 'Zephyr (নারী)',
        'Charon' => 'Charon (পুরুষ)', 'Puck' => 'Puck (পুরুষ)', 'Fenrir' => 'Fenrir (পুরুষ)', 'Orus' => 'Orus (পুরুষ)',
    ];

    public const SPEEDS = ['0.85' => 'ধীর', '1.00' => 'স্বাভাবিক', '1.15' => 'একটু দ্রুত'];

    /** [type, name, text] every company starts with (all editable). */
    public const DEFAULTS = [
        ['reminder', 'বিল রিমাইন্ডার', 'আসসালামু আলাইকুম। {company} থেকে বলছি। {called_for}{name}, আপনার ইন্টারনেট বিল {due} টাকা, শেষ তারিখ {expire_date}। নিরবচ্ছিন্ন সেবার জন্য সময়মতো বিল পরিশোধ করুন।'],
        ['line_off', 'লাইন বন্ধ', 'আসসালামু আলাইকুম। {company} থেকে বলছি। {called_for}{name}, বকেয়া {due} টাকার জন্য আপনার ইন্টারনেট লাইন বন্ধ হয়েছে। বিল পরিশোধ করলেই লাইন চালু হবে।'],
        ['menu', 'রিমাইন্ডারের মেনু', 'পেমেন্ট করার নিয়ম জানতে ১ চাপুন। আমাদের সাথে কথা বলতে ২ চাপুন। আবার শুনতে ০ চাপুন। এই কল আর না চাইলে ৯ চাপুন।'],
        ['menu', 'লাইন বন্ধের মেনু', 'এখনই লাইন চালু করতে ৩ চাপুন। পেমেন্ট করার নিয়ম জানতে ১ চাপুন। আমাদের সাথে কথা বলতে ২ চাপুন। আবার শুনতে ০ চাপুন।'],
        ['result', 'SMS পাঠানো হয়েছে', 'পেমেন্ট করার নিয়ম আপনার মোবাইলে SMS করে পাঠানো হয়েছে।'],
        ['result', 'লাইন চালু হয়েছে', 'আপনার লাইন চালু হয়েছে। অনুগ্রহ করে {reconnect_until} তারিখের মধ্যে বিল পরিশোধ করুন।'],
        ['result', 'লাইন চালু করা গেল না', 'দুঃখিত, এখন নিজে থেকে লাইন চালু করা যাচ্ছে না। {reconnect_reason}'],
        ['result', 'আর কল নয়', 'ঠিক আছে, আপনাকে আর রিমাইন্ডার কল করা হবে না।'],
        ['result', 'ভুল বাটন', 'দুঃখিত, এই বাটনটি সঠিক নয়।'],
        ['result', 'কোনো বাটন চাপেননি', 'কোনো বাটন চাপা হয়নি।'],
        ['result', 'বিদায়', '{company}-এর সাথে থাকার জন্য ধন্যবাদ।'],
        ['hold', 'অপেক্ষা: শুরু', 'একটু অপেক্ষা করুন, আপনার কলটি আমাদের প্রতিনিধির কাছে দেওয়া হচ্ছে।'],
        ['hold', 'অপেক্ষা: মাঝে মাঝে', 'আমাদের প্রতিনিধি এখন ব্যস্ত আছেন, অনুগ্রহ করে লাইনে থাকুন।'],
        ['whisper', 'এজেন্টকে জানানো', 'রিমাইন্ডার কল। {name}, আইডি {id}, বকেয়া {due} টাকা, লাইন {line_status}। কথা বলতে ১ চাপুন।'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['speed' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** A company with no voice templates yet gets the default set. */
    public static function seed(int $companyId): void
    {
        if (static::where('company_id', $companyId)->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $i => [$type, $name, $body]) {
            static::create(['company_id' => $companyId, 'type' => $type, 'name' => $name, 'body' => $body, 'sort' => $i]);
        }
    }

    public function file(): ?string
    {
        $path = $this->audio_path ? storage_path('app/private/'.$this->audio_path) : null;

        return $path && is_file($path) && ! str_contains($this->audio_path, '..') ? $path : null;
    }
}
