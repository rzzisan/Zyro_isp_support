<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An office IP phone on our Asterisk (endpoint ph{id}, written by engine/voice_bot.py). Asterisk registers the
 * phone's PBX extension (o{id}) in its place: calls to that extension ring the phone, the phone's calls go out through
 * it, and every call is recorded and logged in call_logs (office_ext).
 */
class OfficePhone extends Model
{
    protected $guarded = [];

    protected $hidden = ['pbx_password', 'phone_password'];

    /** where the phones register (bsol's public IP; transport-udp in deploy/asterisk/pjsip.conf) */
    public const SERVER = '103.157.253.197';

    public const PORT = 5075;

    protected function casts(): array
    {
        return ['pbx_password' => 'encrypted', 'phone_password' => 'encrypted', 'enabled' => 'boolean', 'status_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (OfficePhone $p) {
            $p->phone_password ??= Str::password(20, symbols: false);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** the phone's SIP username on our server */
    public function login(): string
    {
        return 'ph'.$this->id;
    }
}
