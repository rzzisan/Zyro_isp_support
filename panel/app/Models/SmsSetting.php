<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's SMS gateway (Khudebarta, the same way BSOL sends): send URL, keys (encrypted), sender ID, on/off,
 * rate for the cost estimate; optional balance / delivery-report URLs. One row per company.
 */
class SmsSetting extends Model
{
    /** Khudebarta's HTTP send API (Zisan, 2026-10-15). */
    public const KHUDEBARTA_SEND_URL = 'http://118.67.213.114:3775/sendtext';

    /** Khudebarta web portal API (its Technical Details page and login script, 2026-10-15). */
    public const KHUDEBARTA_BALANCE_URL = 'https://portal.khudebarta.com:9494/api/util/get-current-balance?providerID={provider_id}';

    public const KHUDEBARTA_LOGIN_URL = 'https://portal.khudebarta.com:9494/api/auth/login';

    protected $guarded = [];

    protected $hidden = ['api_key', 'secret_key', 'portal_password', 'portal_token', 'portal_refresh_token'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'secret_key' => 'encrypted', 'portal_password' => 'encrypted',
            'portal_token' => 'encrypted', 'portal_refresh_token' => 'encrypted', 'portal_token_expires_at' => 'datetime',
            'portal_login_failed_at' => 'datetime', 'verify_tls' => 'boolean', 'enabled' => 'boolean',
            'rate' => 'decimal:4', 'balance' => 'decimal:2', 'balance_checked_at' => 'datetime', 'last_check_ok' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(Company|int $company): self
    {
        $id = $company instanceof Company ? $company->id : $company;

        return static::firstOrNew(['company_id' => $id], ['provider' => 'khudebarta', 'verify_tls' => true, 'enabled' => false,
            'endpoint_url' => self::KHUDEBARTA_SEND_URL, 'balance_url' => self::KHUDEBARTA_BALANCE_URL,
            'portal_login_url' => self::KHUDEBARTA_LOGIN_URL]);
    }

    public function configured(): bool
    {
        return filled($this->endpoint_url) && filled($this->api_key) && filled($this->secret_key) && filled($this->caller_id);
    }
}
