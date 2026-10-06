<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the bot wrote (sent, or only drafted in shadow / dry-run mode). */
class WaDraft extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'contact_id', 'message_id', 'context', 'draft', 'mode', 'provider', 'model',
        'error', 'ticket_note'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WaContact::class, 'contact_id');
    }
}
