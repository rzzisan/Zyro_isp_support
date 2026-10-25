<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A support ticket: copied from the billing software (engine/ticket_sync.py; source null or 'history'), or opened in
 * the Desk itself (source 'desk', engine/desk_tickets.py). Either way the engine writes it, never the panel.
 */
class BillingTicket extends Model
{
    public const STATES = ['pending' => 'অপেক্ষমাণ', 'processing' => 'কাজ চলছে', 'solved' => 'সমাধান', 'closed' => 'বন্ধ'];

    public const PRIORITIES = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw' => 'array', 'opened_at' => 'datetime', 'solved_at' => 'datetime', 'synced_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fieldJob(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FieldJob::class, 'ticket_id');
    }

    /** Tickets assigned to or solved by $name (values look like "Maruf (10-07-26), Rifat"). */
    public function scopeForEmployee($query, string $name, bool $assignedOnly = false)
    {
        if ($query->getConnection()->getDriverName() !== 'pgsql') {   // tests (SQLite): the same match with LIKE
            $like = fn ($w, string $col) => $w->orWhere(fn ($x) => collect([$name, "{$name} (%", "{$name},%", "%, {$name}", "%, {$name} (%", "%, {$name},%"])
                ->each(fn ($p) => $x->orWhere($col, 'like', $p)));

            return $query->where(fn ($w) => $like($w, 'assigned_to')->when(! $assignedOnly, fn ($x) => $like($x, 'solved_by')));
        }
        $re = '(^|, )'.preg_quote($name).'( \(|,|$)';

        return $query->where(fn ($w) => $w->whereRaw('assigned_to ~* ?', [$re])
            ->when(! $assignedOnly, fn ($x) => $x->orWhereRaw('solved_by ~* ?', [$re])));
    }

    /** The "ComplainNo." box of the billing ticket: the number the complaint came from (may differ from mobile). */
    public function complainNumber(): ?string
    {
        $n = trim((string) ($this->raw['ComplainNumber'] ?? ''));

        return $n !== '' ? $n : null;
    }

    public function isDesk(): bool
    {
        return $this->source === 'desk';
    }

    /** What was done on it from the Desk (open, assign, edit, solve), oldest first. */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TicketEvent::class, 'complain_id', 'complain_id')
            ->where('ticket_events.company_id', $this->company_id)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, ['pending', 'processing'], true);
    }

    /** "3 ঘণ্টা 20 মিনিট" from opening to solving (or until now while open), in the panel language. */
    public function duration(): ?string
    {
        if (! $this->opened_at) {
            return null;
        }
        $end = $this->solved_at ?? ($this->isOpen() ? now() : null);
        if (! $end) {
            return null;
        }
        $m = max(0, (int) $this->opened_at->diffInMinutes($end));
        $d = intdiv($m, 1440);
        $h = intdiv($m % 1440, 60);

        return trim(($d ? __(':n দিন', ['n' => $d]).' ' : '').($h ? __(':n ঘণ্টা', ['n' => $h]).' ' : '').(! $d ? __(':n মিনিট', ['n' => $m % 60]) : ''));
    }
}
