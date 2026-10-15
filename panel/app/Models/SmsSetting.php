<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's SMS gateway (Khudebarta "SMS HTTP API Published" v5.4.0): send URL, keys (encrypted), sender ID,
 * on/off, rate for the cost estimate, optional delivery-report URL. Balance and validation use the same host.
 * One row per company. (The portal_* / balance_url / provider_id columns of the first version are no longer used:
 * the web portal refuses non-browser logins.)
 */
class SmsSetting extends Model
{
    /** Khudebarta's HTTP API (the old http://118.67.213.114:3775 refuses connections since 2026-10). */
    public const KHUDEBARTA_SEND_URL = 'http://portal.khudebarta.com:3775/sendtext';

    protected $guarded = [];

    protected $hidden = ['api_key', 'secret_key', 'portal_password', 'portal_token', 'portal_refresh_token'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'secret_key' => 'encrypted', 'portal_password' => 'encrypted',
            'portal_token' => 'encrypted', 'portal_refresh_token' => 'encrypted', 'portal_token_expires_at' => 'datetime',
            'portal_login_failed_at' => 'datetime', 'verify_tls' => 'boolean', 'enabled' => 'boolean', 'bill_sms' => 'boolean', 'ticket_open_sms' => 'boolean', 'ticket_solved_sms' => 'boolean', 'reminder_sms' => 'boolean',
            'reminder_due_only' => 'boolean', 'reminder_hour' => 'integer',
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
            'endpoint_url' => self::KHUDEBARTA_SEND_URL]);
    }

    /** An event SMS (bill_paid, ticket_open, ticket_solved) can go: gateway set up and its template chosen. */
    public function eventReady(string $type): bool
    {
        $id = $this->{SmsTemplate::SETTINGS[$type][1]};

        return $this->configured() && $id
            && SmsTemplate::whereKey($id)->where('company_id', $this->company_id)->exists();
    }

    /** The event's SMS is switched on in the settings (forms default their "কাস্টমারকে SMS" to this). */
    public function eventOn(string $type): bool
    {
        return (bool) $this->{SmsTemplate::SETTINGS[$type][0]} && $this->eventReady($type);
    }

    public function billSmsReady(): bool
    {
        return $this->eventReady('bill_paid');
    }

    public function configured(): bool
    {
        return filled($this->endpoint_url) && filled($this->api_key) && filled($this->secret_key) && filled($this->caller_id);
    }
}
