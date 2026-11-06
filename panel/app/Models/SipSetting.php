<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's hotline SIP extension on its PBX (server, port, extension, password). The engine (engine/sip_calls.py)
 * registers it with baresip, which only rings and never answers, and logs each incoming call into call_logs.
 * One row per company; the password is encrypted and never shown again. The PBX web portal's login (portal_*) is kept
 * here too, encrypted, for copying the hotline's call records (App\Services\PbxPortalClient).
 */
class SipSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['password', 'portal_password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'enabled' => 'boolean', 'port' => 'integer', 'status_at' => 'datetime',
            'portal_password' => 'encrypted', 'portal_checked_at' => 'datetime', 'portal_check_ok' => 'boolean', 'portal_pulled_at' => 'datetime'];
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

    public function portalConfigured(): bool
    {
        return filled($this->portal_url) && filled($this->portal_username) && filled($this->portal_password);
    }

    /** Calls pop up in the panel for this company. */
    public static function live(int $companyId): bool
    {
        return static::where('company_id', $companyId)->where('enabled', true)->exists();
    }

    /** Networks office phones may register from (panel → অফিস ফোন → অনুমতির IP তালিকা): [[net, note], ...]. */
    public function officeAllowedIps(): array
    {
        $rows = json_decode((string) $this->office_allowed_ips, true);

        return is_array($rows) ? array_values(array_filter($rows, fn ($r) => is_array($r) && filled($r['net'] ?? null))) : [];
    }

    /** An IPv4 address or network in CIDR form (10.90.90.90, 10.90.90.0/24). */
    public static function validNet(string $net): bool
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', trim($net), $m)) {
            return false;
        }

        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (! isset($m[2]) || (int) $m[2] <= 32);
    }
}
