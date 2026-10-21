<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's hotline SIP extension on its PBX (server, port, extension, password). The engine (engine/sip_calls.py)
 * registers it with baresip, which only rings and never answers, and logs each incoming call into call_logs.
 * One row per company; the password is encrypted and never shown again.
 */
class SipSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'enabled' => 'boolean', 'port' => 'integer', 'status_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(Company|int $company): self
    {
        $id = $company instanceof Company ? $company->id : $company;

        return static::firstOrNew(['company_id' => $id], ['port' => 5060, 'transport' => 'udp', 'enabled' => false]);
    }

    public function configured(): bool
    {
        return filled($this->server) && filled($this->username) && filled($this->password);
    }

    /** Calls pop up in the panel for this company. */
    public static function live(int $companyId): bool
    {
        return static::where('company_id', $companyId)->where('enabled', true)->exists();
    }
}
