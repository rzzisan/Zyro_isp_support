<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'contact_id', 'wa_account_id', 'wa_message_id', 'direction', 'sender',
        'user_id', 'type', 'body', 'media_id', 'media_mime', 'status'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WaContact::class, 'contact_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function waAccount(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class);
    }

    public function hasMedia(): bool
    {
        return filled($this->media_id);
    }
}
