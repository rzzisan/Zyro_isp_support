<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Olt extends Model
{
    public const BRANDS = ['bdcom' => 'BDCOM EPON', 'vsol' => 'VSOL EPON', 'ecom' => 'ECOM EPON (শীঘ্রই)'];

    protected $fillable = ['company_id', 'name', 'brand', 'host', 'snmp_port', 'community', 'router_identity', 'enabled'];

    protected $hidden = ['community'];

    protected function casts(): array
    {
        return ['community' => 'encrypted', 'enabled' => 'boolean', 'last_poll_ok' => 'boolean', 'last_poll_at' => 'datetime', 'vlans' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function onus(): HasMany
    {
        return $this->hasMany(Onu::class);
    }
}
