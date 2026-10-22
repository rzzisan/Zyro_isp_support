<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /** PPPoE username of the line this chat is about, filled by withLineNames() (not a column). */
    public ?string $linePppoe = null;

    /**
     * Fill linePppoe for a list of chats in two queries: the agent's or bot's customer ID, else the only current
     * line on the number (same rule as suggestedCustomerId()).
     */
    public static function withLineNames(iterable $chats): void
    {
        $chats = collect($chats);
        if ($chats->isEmpty()) {
            return;
        }
        $companyId = $chats->first()->company_id;
        $ids = $chats->pluck('customer_id')->filter()->unique()->values();
        $byId = $ids->isEmpty() ? collect() : BillingCustomer::where('company_id', $companyId)->whereNull('gone_at')
            ->whereIn('customer_id', $ids)->pluck('username', 'customer_id');
        $numbers = $chats->filter(fn ($c) => ! $c->customer_id && ! $c->hidesNumber())->pluck('wa_number')->unique()->values();
        $byNumber = $numbers->isEmpty() ? collect() : BillingCustomer::where('company_id', $companyId)->whereNull('gone_at')
            ->where('is_left', false)->whereIn('mobile_normalized', $numbers)->get(['mobile_normalized', 'username'])
            ->groupBy('mobile_normalized')->filter(fn ($g) => $g->count() === 1)->map(fn ($g) => $g->first()->username);
        foreach ($chats as $c) {
            $u = $c->customer_id ? $byId->get($c->customer_id) : $byNumber->get($c->wa_number);
            $c->linePppoe = trim((string) $u) ?: null;
        }
    }

    /** "mp.sharmin(Sharmi…)" for a known customer (first 6 letters, so it fits one line), else the WhatsApp name or number. */
    public function listName(): string
    {
        $name = trim((string) $this->name);
        if ($this->linePppoe) {
            $short = mb_strlen($name) > 6 ? rtrim(mb_substr($name, 0, 6)).'…' : $name;

            return $short !== '' ? "{$this->linePppoe}({$short})" : $this->linePppoe;
        }

        return $name !== '' ? $name : $this->displayNumber();
    }

    /**
     * The inbox list (panel and app): a company's chats, newest first, filtered by box (all, waiting, mine, paused,
     * staff, muted) and a search on name, customer ID or number.
     */
    public static function inbox(int $companyId, string $box, string $search, ?int $userId): Builder
    {
        $q = static::query()->where('company_id', $companyId)->with(['lastMessage', 'assignedUser'])
            ->orderByDesc('last_message_at');
        if (($s = trim($search)) !== '') {
            $digits = preg_replace('/\D/', '', $s);
            $pppoe = BillingCustomer::select('customer_id')->whereColumn('billing_customers.company_id', 'wa_contacts.company_id')
                ->where('username', 'ilike', "%{$s}%");
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('customer_id', $s)->orWhereIn('customer_id', $pppoe)
                ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('wa_number', 'like', '%'.ltrim($digits, '0').'%')));
        }
        $lastDir = "(SELECT m.direction FROM wa_messages m WHERE m.contact_id = wa_contacts.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1)";
        $staff = fn ($x) => $x->from('technicians')
            ->whereColumn('technicians.company_id', 'wa_contacts.company_id')->whereColumn('technicians.wa_number', 'wa_contacts.wa_number');
        match ($box) {
            'waiting' => $q->whereRaw("{$lastDir} = 'in'")->whereNotExists($staff),
            'staff' => $q->whereExists($staff),
            'mine' => $q->where('assigned_user_id', $userId),
            'paused' => $q->where(fn ($w) => $w->where('bot_paused', true)->orWhere('bot_paused_until', '>', now())),
            'muted' => $q->where('notify_muted', true),
            default => null,
        };

        return $q;
    }

    /** Chats whose last message is the customer's (not a technician's): they wait for an answer. */
    public static function waitingCount(int $companyId): int
    {
        return (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT count(*) AS n FROM wa_contacts c WHERE c.company_id = ? AND (
                 SELECT m.direction FROM wa_messages m WHERE m.contact_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) = 'in'
               AND NOT EXISTS (SELECT 1 FROM technicians t WHERE t.company_id = c.company_id AND t.wa_number = c.wa_number)",
            [$companyId])->n;
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

    /** A manual pause: the bot stays quiet on this number for $hours, then answers again on its own. */
    public function pauseBot(int $hours = 12): void
    {
        $this->forceFill(['bot_paused' => false, 'bot_paused_until' => now()->addHours($hours)])->save();
    }

    /** The bot answers again; a customer already identified stays identified, an unfinished identification starts fresh. */
    public function resumeBot(): void
    {
        $state = $this->ident_state;
        $keep = ($state['stage'] ?? null) === 'ok' || ($state['mode'] ?? null) === 'technician';
        $this->forceFill(['bot_paused' => false, 'bot_paused_until' => null, 'ident_state' => $keep ? $state : null])->save();
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
            return $this->wa_username ? '@'.ltrim($this->wa_username, '@') : __('নম্বর লুকানো');
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
