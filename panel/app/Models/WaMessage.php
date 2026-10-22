<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A WhatsApp message. Deleted ones (owner, sent messages only) are hidden in Desk; Meta can't unsend, so the customer keeps them. */
class WaMessage extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'contact_id', 'wa_account_id', 'wa_message_id', 'direction', 'sender',
        'user_id', 'type', 'body', 'media_id', 'media_mime', 'status'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'deleted_at' => 'datetime'];
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

    /** Only the company owner, and only messages that went out from us. */
    public function deletableBy(?User $user): bool
    {
        return $this->direction === 'out' && $user?->roleIn($this->company) === 'owner';
    }

    public function softDelete(User $user): void
    {
        $this->forceFill(['deleted_by' => $user->id])->save();
        $this->delete();
    }

    public function hasMedia(): bool
    {
        return filled($this->media_id);
    }
}
