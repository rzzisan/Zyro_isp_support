<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's bKash merchant webhook (engine/bkash_webhook.py): the switch, our wallet, the confirmed SNS topic. One row per company. */
class BkashWebhook extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settle_in_billing' => 'boolean', 'enable_line' => 'boolean',
            'pending_at' => 'datetime', 'confirmed_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(Company $company): self
    {
        return static::firstOrNew(['company_id' => $company->id], ['enabled' => false, 'settle_in_billing' => false, 'enable_line' => true]);
    }

    /** The URL to give bKash technical support. */
    public static function url(Company $company): string
    {
        return 'https://desk.zyrotechbd.com/pay/bkash/webhook/'.$company->slug;
    }
}
