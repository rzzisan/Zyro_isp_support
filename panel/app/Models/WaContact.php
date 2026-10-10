<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One customer WhatsApp number talking to a company (written by the engine, worked in the inbox). */
class WaContact extends Model
{
    protected $fillable = ['company_id', 'wa_number', 'name', 'customer_id', 'ident_state', 'bot_paused_until',
        'bot_paused', 'assigned_user_id', 'last_message_at', 'notify_muted'];

    protected function casts(): array
    {
        return [
            'ident_state' => 'array',
            'bot_paused' => 'boolean',
            'notify_muted' => 'boolean',
            'bot_paused_until' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class, 'contact_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(WaDraft::class, 'contact_id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(WaMessage::class, 'contact_id')->latestOfMany();
    }

    public function isBotPaused(): bool
    {
        return $this->bot_paused || ($this->bot_paused_until && $this->bot_paused_until->isFuture());
    }

    /** WhatsApp only allows free-form replies within 24 hours of the customer's last message. */
    public function lastIncomingAt(): ?\Illuminate\Support\Carbon
    {
        $at = $this->messages()->where('direction', 'in')->max('created_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    public function windowOpen(): bool
    {
        $at = $this->lastIncomingAt();

        return $at !== null && $at->gt(now()->subHours(24));
    }

    /** 8801XXXXXXXXX -> 01XXXXXXXXX for display. */
    public function displayNumber(): string
    {
        return str_starts_with($this->wa_number, '880') ? '0'.substr($this->wa_number, 3) : $this->wa_number;
    }

    /** The field technician using this number, if it is one of the company's technicians. */
    public function technician(): ?Technician
    {
        return Technician::where('company_id', $this->company_id)->where('wa_number', $this->wa_number)->where('active', true)->first();
    }
}
