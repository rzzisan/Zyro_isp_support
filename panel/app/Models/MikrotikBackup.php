<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A MikroTik backup run: .rsc export (with PPP secrets) and binary .backup on the private disk. Written by the engine. */
class MikrotikBackup extends Model
{
    protected $fillable = ['company_id', 'router_id', 'router_name', 'trigger', 'status', 'rsc_path', 'rsc_size',
        'backup_path', 'backup_size', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function path(string $kind): ?string
    {
        return match ($kind) {
            'rsc' => $this->rsc_path,
            'backup' => $this->backup_path,
            default => null,
        };
    }
}
