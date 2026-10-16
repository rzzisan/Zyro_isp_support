<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A later payment against a new connection's due. */
class ConnectionPayment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'paid' => 'decimal:2', 'discount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NewConnection::class, 'connection_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'collected_by_id');
    }
}
