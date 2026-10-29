<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\StaffChatMessage;
use App\Support\StaffChat;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * টিম চ্যাট in the panel (App\Support\StaffChat), like the inbox: chats on the left, the open chat on the right. Text,
 * a photo, or a voice message recorded with the browser's microphone. Every member of the company may use it.
 */
class TeamChat extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $slug = 'team-chat';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.app.team-chat';

    #[Url]
    public string $chat = '';

    public string $text = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $photo = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $voice = null;

    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('টিম চ্যাট');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('টিম চ্যাট');
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company && auth()->check();
    }

    public static function getNavigationBadge(): ?string
    {
        $company = Filament::getTenant();
        $n = $company instanceof Company && auth()->user() ? StaffChat::unreadTotal(auth()->user(), $company) : 0;

        return $n ? (string) $n : null;
    }

    private function company(): Company
    {
        /** @var Company */
        return Filament::getTenant();
    }

    /** [key, group, other] of the open chat, or null. */
    public function current(): ?array
    {
        return $this->chat !== '' ? StaffChat::resolve(auth()->user(), $this->company(), $this->chat) : null;
    }

    public function open(string $ref): void
    {
        $this->chat = $ref;
        $this->reset('text', 'photo', 'voice');
        $this->resetValidation();
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    public function send(): void
    {
        $c = $this->current();
        abort_unless($c, 404);
        $this->validate([
            'text' => [$this->photo ? 'nullable' : 'required', 'string', 'max:4000'],
            'photo' => ['nullable', 'file', 'max:15360', 'mimetypes:'.implode(',', StaffChat::IMAGE_TYPES)],
        ], [
            'text.required' => __('মেসেজ লিখুন'),
            'photo.max' => __('ফাইল ১৫ MB-এর বেশি হতে পারবে না'),
            'photo.mimetypes' => __('শুধু ছবি (JPG/PNG/WEBP) পাঠানো যায়'),
        ]);
        StaffChat::send($this->company(), auth()->user(), $c, $this->text, $this->photo, $this->photo ? 'image' : null);
        $this->reset('text', 'photo');
    }

    /** The browser recorded a voice message and uploaded it into $voice. */
    public function sendVoice(int $seconds): void
    {
        $c = $this->current();
        abort_unless($c && $this->voice, 404);
        if (! StaffChat::voiceExtension($this->voice->getMimeType())) {
            $this->voice = null;
            Notification::make()->danger()->title(__('এই ভয়েস ফাইল চলবে না'))->send();

            return;
        }
        StaffChat::send($this->company(), auth()->user(), $c, null, $this->voice, 'voice', $seconds);
        $this->voice = null;
    }

    public function deleteMessage(int $id): void
    {
        $c = $this->current();
        abort_unless($c, 404);
        $m = StaffChatMessage::where('company_id', $this->company()->id)->where('chat', $c[0])->findOrFail($id);
        abort_unless($m->deletableBy(auth()->user(), StaffChat::role(auth()->user(), $this->company())), 403);
        if ($path = $m->file()) {
            @unlink($path);
        }
        $m->forceFill(['deleted_at' => now(), 'deleted_by' => auth()->id(), 'body' => null])->save();
    }

    public function toggleMute(): void
    {
        $c = $this->current();
        abort_unless($c, 404);
        $muted = ! StaffChat::muted(auth()->user(), $this->company(), $c[0]);
        StaffChat::setMuted(auth()->user(), $this->company(), $c[0], $muted);
        Notification::make()->success()->title($muted ? __('এই চ্যাটের নোটিফিকেশন বন্ধ') : __('এই চ্যাটের নোটিফিকেশন চালু'))->send();
    }

    protected function getViewData(): array
    {
        $me = auth()->user();
        $company = $this->company();
        $chats = StaffChat::list($me, $company);
        $c = $this->current();
        $messages = collect();
        $seen = 0;
        if ($c) {
            [$key, , $other] = $c;
            $messages = StaffChatMessage::where('company_id', $company->id)->where('chat', $key)->with('sender:id,name')
                ->orderByDesc('id')->limit(150)->get()->reverse()->values();
            StaffChat::markRead($me, $company, $key, (int) $messages->last()?->id);
            $seen = $other ? StaffChat::seenUpTo($company, $key, $other->id) : 0;
            // the list was read before this chat was marked read
            $chats = $chats->map(fn ($r) => $r['ref'] === $this->chat ? ['unread' => 0] + $r : $r);
        }
        $known = $chats->pluck('ref')->all();

        return [
            'chats' => $chats,
            'current' => $c,
            'messages' => $messages,
            'seen' => $seen,
            'me' => $me,
            'role' => StaffChat::role($me, $company),
            'muted' => $c ? StaffChat::muted($me, $company, $c[0]) : false,
            // new one-to-one chat: members without a chat yet, filtered by the search box
            'people' => $company->users()->where('users.id', '!=', $me->id)->orderBy('users.name')->get(['users.id', 'users.name'])
                ->reject(fn ($u) => in_array('u'.$u->id, $known, true))
                ->filter(fn ($u) => $this->search === '' || str_contains(mb_strtolower($u->name), mb_strtolower($this->search)))
                ->values(),
        ];
    }
}
