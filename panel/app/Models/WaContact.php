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
        'bot_paused', 'assigned_user_id', 'last_message_at', 'notify_muted', 'customer_set_by', 'customer_set_at'];

    protected function casts(): array
    {
        return [
            'ident_state' => 'array',
            'bot_paused' => 'boolean',
            'notify_muted' => 'boolean',
            'bot_paused_until' => 'datetime',
            'last_message_at' => 'datetime',
            'customer_set_at' => 'datetime',
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

    public function customerSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_set_by');
    }

    /** The line this chat is about: the agent's or the bot's customer ID, else the only current line on this number. */
    public function suggestedCustomerId(): ?string
    {
        if ($this->customer_id) {
            return $this->customer_id;
        }
        if ($this->hidesNumber()) {
            return null;
        }
        $ids = BillingCustomer::where('company_id', $this->company_id)->whereNull('gone_at')->where('is_left', false)
            ->where('mobile_normalized', $this->wa_number)->limit(2)->pluck('customer_id');

        return $ids->count() === 1 ? $ids->first() : null;
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

    /** 8801XXXXXXXXX -> 01XXXXXXXXX for display; a user who hides the number shows the username. */
    public function displayNumber(): string
    {
        if ($this->hidesNumber()) {
            return $this->wa_username ? '@'.ltrim($this->wa_username, '@') : 'নম্বর লুকানো';
        }

        return str_starts_with($this->wa_number, '880') ? '0'.substr($this->wa_number, 3) : $this->wa_number;
    }

    /** No phone number from Meta, only a business-scoped user ID ("BD.1234...") kept in wa_number. */
    public function hidesNumber(): bool
    {
        return str_contains((string) $this->wa_number, '.');
    }

    /** Who a Cloud API message goes to: the phone number, or the BSUID for a user who hides it. */
    public function recipient(): array
    {
        return $this->hidesNumber() ? ['recipient' => $this->wa_number] : ['to' => $this->wa_number];
    }

    /** The field technician using this number, if it is one of the company's technicians. */
    public function technician(): ?Technician
    {
        return Technician::where('company_id', $this->company_id)->where('wa_number', $this->wa_number)->where('active', true)->first();
    }
}
