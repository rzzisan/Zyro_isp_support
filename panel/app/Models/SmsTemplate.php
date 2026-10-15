<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ready SMS text. general: picked in the SMS form; bill_paid / ticket_open / ticket_solved: sent by the Desk after
 * "বিল নিন", a new ticket, a solved ticket; bill_reminder: before the expire date (sms:bill-reminders) — the one chosen
 * in the gateway settings. Placeholders:
 * App\Support\Sms::placeholders().
 */
class SmsTemplate extends Model
{
    public const TYPES = ['general' => 'সাধারণ', 'bill_paid' => 'বিল পরিশোধ', 'ticket_open' => 'টিকিট খোলা',
        'ticket_solved' => 'টিকিট সমাধান', 'bill_reminder' => 'বিল রিমাইন্ডার'];

    /** type => [name, body] of the template every company starts with */
    public const DEFAULTS = [
        'bill_paid' => ['বিল পরিশোধ', 'প্রিয় {name}, আপনার {months} মাসের বিল {paid} টাকা পরিশোধ হয়েছে। ছাড়: {discount} টাকা, বকেয়া: {due} টাকা। ধন্যবাদ, {company}'],
        'ticket_open' => ['টিকিট খোলা', 'প্রিয় {name}, আপনার অভিযোগ গ্রহণ করা হয়েছে। টিকিট নং {ticket_no}, সমস্যা: {problem}। আমাদের টেকনিশিয়ান দ্রুত যোগাযোগ করবেন। {company}'],
        'ticket_solved' => ['টিকিট সমাধান', 'প্রিয় {name}, আপনার টিকিট {ticket_no} এর সমস্যার সমাধান হয়েছে। কোনো সমস্যা থাকলে জানাবেন। {company}'],
        'bill_reminder' => ['বিল রিমাইন্ডার', 'প্রিয় {name}, আপনার ইন্টারনেট সংযোগের মেয়াদ {expire_date} তারিখে শেষ হবে। বকেয়া {due} টাকা। নিরবচ্ছিন্ন সেবার জন্য সময়মতো বিল পরিশোধ করুন। {company}'],
    ];

    /** sms_settings columns per event type: [on/off, template id] */
    public const SETTINGS = [
        'bill_paid' => ['bill_sms', 'bill_template_id'],
        'ticket_open' => ['ticket_open_sms', 'ticket_open_template_id'],
        'ticket_solved' => ['ticket_solved_sms', 'ticket_solved_template_id'],
        'bill_reminder' => ['reminder_sms', 'reminder_template_id'],
    ];

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The company's templates of an event type; a company without one gets the default Bangla one. */
    public static function optionsFor(int $companyId, string $type): array
    {
        if (! static::where('company_id', $companyId)->where('type', $type)->exists() && isset(self::DEFAULTS[$type])) {
            [$name, $body] = self::DEFAULTS[$type];
            static::create(['company_id' => $companyId, 'type' => $type, 'name' => $name, 'body' => $body]);
        }

        return static::where('company_id', $companyId)->where('type', $type)->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all();
    }

    public static function billPaidOptions(int $companyId): array
    {
        return static::optionsFor($companyId, 'bill_paid');
    }
}
