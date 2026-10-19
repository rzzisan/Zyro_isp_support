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

    /** box => Bangla label, translated with __() where it is shown */
    public const BOXES = ['all' => 'সব', 'waiting' => 'উত্তরের অপেক্ষায়', 'mine' => 'আমার', 'paused' => 'বট থামানো', 'staff' => 'স্টাফ',
        'muted' => 'নোটিফিকেশন বন্ধ'];

    /** Up to 60 chats for the list, newest first, with their last message and the "waiting" flag. */
    public function chatList(): Collection
    {
        return WaContact::inbox(Filament::getTenant()->getKey(), $this->box, $this->search, auth()->id())->limit(60)->get();
    }

    public function waitingCount(): int
    {
        return WaContact::waitingCount(Filament::getTenant()->getKey());
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
            $l->isSameDay($today->copy()->subDay()) => __('গতকাল'),
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
