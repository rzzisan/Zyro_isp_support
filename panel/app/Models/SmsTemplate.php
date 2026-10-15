<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ready SMS text. general: picked in the SMS form; bill_paid: sent after "বিল নিন" (the one chosen in the gateway
 * settings). Placeholders like {name} {due} {paid}: App\Support\Sms::placeholders().
 */
class SmsTemplate extends Model
{
    public const TYPES = ['general' => 'সাধারণ', 'bill_paid' => 'বিল পরিশোধ'];

    public const BILL_PAID_BODY = 'প্রিয় {name}, আপনার {months} মাসের বিল {paid} টাকা পরিশোধ হয়েছে। ছাড়: {discount} টাকা, বকেয়া: {due} টাকা। ধন্যবাদ, {company}';

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The company's bill-payment templates; a company without one gets the default Bangla one. */
    public static function billPaidOptions(int $companyId): array
    {
        $q = static::where('company_id', $companyId)->where('type', 'bill_paid');
        if (! $q->exists()) {
            static::create(['company_id' => $companyId, 'type' => 'bill_paid', 'name' => 'বিল পরিশোধ', 'body' => self::BILL_PAID_BODY]);
        }

        return static::where('company_id', $companyId)->where('type', 'bill_paid')->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all();
    }
}
