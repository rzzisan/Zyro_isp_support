<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SMS gateway account of a company (engine/sms_gateways/*): Khudebarta (api key + secret key) or Bulk SMS Dhaka
 * (api key only, IP whitelist on their side). Keys are encrypted and never shown again. A gateway that refused for an
 * account reason gets failed_at and is skipped (the fallback takes over) until someone clears it.
 */
class SmsGateway extends Model
{
    /** provider => [label, default URL, needs a secret key] */
    public const PROVIDERS = [
        'khudebarta' => ['Khudebarta', SmsSetting::KHUDEBARTA_SEND_URL, true],
        'bulksmsdhaka' => ['Bulk SMS Dhaka', 'https://bulksmsdhaka.net/api', false],
    ];

    protected $guarded = [];

    protected $hidden = ['api_key', 'secret_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'secret_key' => 'encrypted', 'verify_tls' => 'boolean', 'rate' => 'decimal:4',
            'low_balance' => 'decimal:2', 'balance' => 'decimal:2', 'balance_sms' => 'integer', 'balance_checked_at' => 'datetime',
            'last_check_ok' => 'boolean', 'failed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function providerOptions(): array
    {
        return array_map(fn ($p) => $p[0], self::PROVIDERS);
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider][0] ?? (string) $this->provider;
    }

    public function name(): string
    {
        return $this->label ?: $this->providerLabel();
    }

    public function needsSecret(): bool
    {
        return (bool) (self::PROVIDERS[$this->provider][2] ?? false);
    }

    public function configured(): bool
    {
        return isset(self::PROVIDERS[$this->provider]) && filled($this->endpoint_url) && filled($this->api_key)
            && filled($this->caller_id) && (! $this->needsSecret() || filled($this->secret_key));
    }

    /** Set up and not stopped by an account error. */
    public function usable(): bool
    {
        return $this->configured() && $this->failed_at === null;
    }

    public function lowBalance(): bool
    {
        return $this->low_balance !== null && $this->balance !== null && $this->last_check_ok !== false
            && (float) $this->balance < (float) $this->low_balance;
    }
}
