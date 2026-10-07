<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A support ticket copied from the billing software (read-only here; engine/ticket_sync.py writes it). */
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

    /** Tickets assigned to or solved by $name (values look like "Maruf (10-07-26), Rifat"). */
    public function scopeForEmployee($query, string $name, bool $assignedOnly = false)
    {
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

    public function isOpen(): bool
    {
        return in_array($this->state, ['pending', 'processing'], true);
    }

    /** "3 ঘণ্টা 20 মিনিট" from opening to solving (or until now while open). */
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

        return trim(($d ? "{$d} দিন " : '').($h ? "{$h} ঘণ্টা " : '').(! $d ? ($m % 60).' মিনিট' : ''));
    }
}
