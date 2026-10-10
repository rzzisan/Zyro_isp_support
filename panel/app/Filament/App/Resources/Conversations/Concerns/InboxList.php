<?php

namespace App\Filament\App\Resources\Conversations\Concerns;

use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Models\WaContact;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/** The left-hand chat list shared by the inbox page and a single conversation. */
trait InboxList
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'box')]
    public string $box = 'all';

    public const BOXES = ['all' => 'সব', 'waiting' => 'উত্তরের অপেক্ষায়', 'mine' => 'আমার', 'paused' => 'বট থামানো', 'staff' => 'স্টাফ',
        'muted' => 'নোটিফিকেশন বন্ধ'];

    /** Up to 60 chats for the list, newest first, with their last message and the "waiting" flag. */
    public function chatList(): Collection
    {
        $company = Filament::getTenant()->getKey();
        $q = WaContact::query()->where('company_id', $company)->with(['lastMessage', 'assignedUser'])
            ->orderByDesc('last_message_at');
        if (($s = trim($this->search)) !== '') {
            $digits = preg_replace('/\D/', '', $s);
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('customer_id', $s)
                ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('wa_number', 'like', '%'.ltrim($digits, '0').'%')));
        }
        $lastDir = "(SELECT m.direction FROM wa_messages m WHERE m.contact_id = wa_contacts.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1)";
        match ($this->box) {
            'waiting' => $q->whereRaw("{$lastDir} = 'in'")->whereNotExists(fn ($x) => $x->from('technicians')
                ->whereColumn('technicians.company_id', 'wa_contacts.company_id')->whereColumn('technicians.wa_number', 'wa_contacts.wa_number')),
            'staff' => $q->whereExists(fn ($x) => $x->from('technicians')
                ->whereColumn('technicians.company_id', 'wa_contacts.company_id')->whereColumn('technicians.wa_number', 'wa_contacts.wa_number')),
            'mine' => $q->where('assigned_user_id', auth()->id()),
            'paused' => $q->where(fn ($w) => $w->where('bot_paused', true)->orWhere('bot_paused_until', '>', now())),
            'muted' => $q->where('notify_muted', true),
            default => null,
        };

        return $q->limit(60)->get();
    }

    public function waitingCount(): int
    {
        return (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT count(*) AS n FROM wa_contacts c WHERE c.company_id = ? AND (
                 SELECT m.direction FROM wa_messages m WHERE m.contact_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) = 'in'
               AND NOT EXISTS (SELECT 1 FROM technicians t WHERE t.company_id = c.company_id AND t.wa_number = c.wa_number)",
            [Filament::getTenant()->getKey()])->n;
    }

    public function setBox(string $box): void
    {
        $this->box = array_key_exists($box, self::BOXES) ? $box : 'all';
    }

    public function chatUrl(WaContact $c): string
    {
        return ConversationResource::getUrl('view', array_filter(['record' => $c, 'q' => $this->search ?: null,
            'box' => $this->box !== 'all' ? $this->box : null]));
    }

    /** "10:24 AM" today, "গতকাল", else "06 Oct". */
    public static function shortTime(?Carbon $at): string
    {
        if (! $at) {
            return '';
        }
        $l = $at->copy()->timezone('Asia/Dhaka');
        $today = now('Asia/Dhaka');

        return match (true) {
            $l->isSameDay($today) => $l->format('g:i A'),
            $l->isSameDay($today->copy()->subDay()) => 'গতকাল',
            default => $l->format('d M'),
        };
    }

    public static function initials(?string $name, string $fallback): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return mb_substr($fallback, -2);
        }
        $parts = preg_split('/\s+/u', $name);

        return mb_strtoupper(mb_substr($parts[0], 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    }

    public static function avatarColor(string $key): string
    {
        $colors = ['#0f7c7b', '#6366f1', '#d97706', '#db2777', '#0891b2', '#65a30d', '#7c3aed', '#dc2626'];

        return $colors[crc32($key) % count($colors)];
    }
}
