<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Membership;
use App\Models\StaffChatMessage;
use App\Models\User;
use App\Models\WalkieGroup;
use App\Services\Engine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * টিম চ্যাট: Desk users of a company message each other in the app and the panel. Everyone may write to everyone one
 * to one; the walkie-talkie groups are also the chat groups (same members). The owner sees every group but nobody's
 * one-to-one chats. A chat is addressed from one user's side by a ref: "g{group}" or "u{the other user}"; stored by a
 * key: "g{group}" or "u{lower id}-{higher id}". Images and voice are kept KEEP_DAYS, text always.
 */
class StaffChat
{
    public const KEEP_DAYS = 90;

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /** What phones and browsers record: AAC in MP4 (Android), Opus in WebM/Ogg (Chrome, Firefox). */
    public const VOICE_TYPES = ['audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg', 'audio/webm' => 'webm', 'video/webm' => 'webm', 'video/mp4' => 'm4a', 'audio/3gpp' => '3gp'];

    public static function role(User $user, Company $company): ?string
    {
        return Membership::where('company_id', $company->id)->where('user_id', $user->id)->value('role');
    }

    /** The groups this user may open: their own, or every group for the owner. */
    public static function groups(User $user, Company $company): Collection
    {
        $q = WalkieGroup::where('company_id', $company->id)->orderBy('name');
        if (static::role($user, $company) !== 'owner') {
            $q->whereHas('memberships', fn ($m) => $m->where('company_user.user_id', $user->id));
        }

        return $q->get();
    }

    /**
     * [key, group|null, other user|null] of a ref this user may open, or null (no such chat, not theirs, or
     * themselves).
     */
    public static function resolve(User $user, Company $company, string $ref): ?array
    {
        if (preg_match('/^g(\d+)$/', $ref, $m)) {
            $g = static::groups($user, $company)->firstWhere('id', (int) $m[1]);

            return $g ? ["g{$g->id}", $g, null] : null;
        }
        if (preg_match('/^u(\d+)$/', $ref, $m) && (int) $m[1] !== $user->id) {
            $other = $company->users()->whereKey((int) $m[1])->first();

            return $other ? [static::directKey($user->id, $other->id), null, $other] : null;
        }

        return null;
    }

    public static function directKey(int $a, int $b): string
    {
        return 'u'.min($a, $b).'-'.max($a, $b);
    }

    /** The ref of a stored chat as this user sees it. */
    public static function refOf(string $key, int $me): string
    {
        if (str_starts_with($key, 'g')) {
            return $key;
        }
        [$a, $b] = array_map('intval', explode('-', substr($key, 1)));

        return 'u'.($a === $me ? $b : $a);
    }

    /**
     * The user's chats, latest first: every group they may open (even with no message yet) and every one-to-one
     * chat that has a message. [{ref, kind, title, members, last: {text, at, mine}|null, unread, muted}]
     */
    public static function list(User $user, Company $company): Collection
    {
        $groups = static::groups($user, $company)->load('memberships');
        $directLike = ['u'.$user->id.'-%', 'u%-'.$user->id];
        $keys = $groups->map(fn ($g) => "g{$g->id}")->all();
        $direct = StaffChatMessage::where('company_id', $company->id)
            ->where(fn ($q) => $q->where('chat', 'like', $directLike[0])->orWhere('chat', 'like', $directLike[1]))
            ->distinct()->pluck('chat')
            ->filter(fn ($k) => in_array($user->id, array_map('intval', explode('-', substr($k, 1))), true))->values()->all();
        $keys = [...$keys, ...$direct];

        $last = StaffChatMessage::where('company_id', $company->id)->whereIn('id',
            StaffChatMessage::where('company_id', $company->id)->whereIn('chat', $keys)->groupBy('chat')->selectRaw('max(id)'))
            ->with('sender:id,name')->get()->keyBy('chat');
        $reads = DB::table('staff_chat_reads')->where('company_id', $company->id)->where('user_id', $user->id)
            ->whereIn('chat', $keys)->get()->keyBy('chat');
        $unread = static::unreadCounts($user, $company, $keys, $reads);
        $names = $company->users()->pluck('users.name', 'users.id');

        $rows = collect($keys)->map(function (string $key) use ($user, $groups, $last, $reads, $unread, $names) {
            $m = $last[$key] ?? null;
            if (str_starts_with($key, 'g')) {
                $g = $groups->firstWhere('id', (int) substr($key, 1));
                $title = $g->name;
                $members = $g->memberships->count();
            } else {
                $other = (int) substr(static::refOf($key, $user->id), 1);
                $title = $names[$other] ?? __('প্রাক্তন সদস্য');
                $members = 2;
            }

            return ['ref' => static::refOf($key, $user->id), 'kind' => str_starts_with($key, 'g') ? 'group' : 'direct',
                'title' => $title, 'members' => $members,
                'last' => $m ? ['text' => static::preview($m), 'at' => $m->created_at->toIso8601String(),
                    'mine' => $m->sender_id === $user->id, 'sender' => $m->sender?->name] : null,
                'unread' => $unread[$key] ?? 0, 'muted' => (bool) ($reads[$key]->muted ?? false)];
        });

        return $rows->sortByDesc(fn ($r) => $r['last']['at'] ?? '')->values();
    }

    /** chat key => messages from others after the user's read position. */
    private static function unreadCounts(User $user, Company $company, array $keys, Collection $reads): array
    {
        if (! $keys) {
            return [];
        }
        $q = StaffChatMessage::where('company_id', $company->id)->whereIn('chat', $keys)->whereNull('deleted_at')
            ->where(fn ($s) => $s->where('sender_id', '!=', $user->id)->orWhereNull('sender_id'));
        $q->where(function ($w) use ($keys, $reads) {
            foreach ($keys as $k) {
                $w->orWhere(fn ($c) => $c->where('chat', $k)->where('id', '>', (int) ($reads[$k]->last_read_id ?? 0)));
            }
        });

        return $q->groupBy('chat')->selectRaw('chat, count(*) as n')->pluck('n', 'chat')->map(fn ($n) => (int) $n)->all();
    }

    /** All the user's unread messages (the sidebar badge, the app's tab badge). */
    public static function unreadTotal(User $user, Company $company): int
    {
        return (int) static::list($user, $company)->where('muted', false)->sum('unread');
    }

    public static function preview(StaffChatMessage $m): string
    {
        if ($m->deleted_at) {
            return __('মেসেজ মুছে ফেলা হয়েছে');
        }
        if (filled($m->body)) {
            return Str::limit(trim($m->body), 80);
        }

        return $m->media_type === 'voice' ? '🎤 '.__('ভয়েস') : '📷 '.__('ছবি');
    }

    public static function markRead(User $user, Company $company, string $key, int $upTo): void
    {
        if ($upTo <= 0) {
            return;
        }
        $row = DB::table('staff_chat_reads')->where(['company_id' => $company->id, 'user_id' => $user->id, 'chat' => $key]);
        if ($row->exists()) {
            $row->where('last_read_id', '<', $upTo)->update(['last_read_id' => $upTo]);
        } else {
            DB::table('staff_chat_reads')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'chat' => $key, 'last_read_id' => $upTo]);
        }
    }

    public static function setMuted(User $user, Company $company, string $key, bool $muted): void
    {
        DB::table('staff_chat_reads')->updateOrInsert(['company_id' => $company->id, 'user_id' => $user->id, 'chat' => $key],
            ['muted' => $muted]);
    }

    public static function muted(User $user, Company $company, string $key): bool
    {
        return (bool) DB::table('staff_chat_reads')->where(['company_id' => $company->id, 'user_id' => $user->id, 'chat' => $key])->value('muted');
    }

    /** How far the other person of a one-to-one chat has read (the ✓✓ on my messages). */
    public static function seenUpTo(Company $company, string $key, int $otherId): int
    {
        return (int) DB::table('staff_chat_reads')->where(['company_id' => $company->id, 'user_id' => $otherId, 'chat' => $key])->value('last_read_id');
    }

    /** Is this file a voice message we can keep, and as what extension? (null = no) */
    public static function voiceExtension(?string $mime): ?string
    {
        return static::VOICE_TYPES[strtolower((string) $mime)] ?? null;
    }

    /**
     * Save a message (text, image or voice) and push it to the other members' phones and browsers.
     * $kind: null (text only), 'image' or 'voice'.
     */
    public static function send(Company $company, User $user, array $chat, ?string $body, ?UploadedFile $file = null,
        ?string $kind = null, ?int $seconds = null): StaffChatMessage
    {
        [$key, $group, $other] = $chat;
        $media = [];
        if ($file) {
            $media = $kind === 'voice' ? static::storeVoice($company, $file, $seconds) : static::storeImage($company, $file);
        }
        $m = StaffChatMessage::create([
            'company_id' => $company->id, 'chat' => $key, 'walkie_group_id' => $group?->id, 'sender_id' => $user->id,
            'body' => filled($body) ? trim($body) : null, ...$media,
        ]);
        static::markRead($user, $company, $key, $m->id);
        static::notify($company, $user, $chat, $m);
        static::pruneDaily();

        return $m;
    }

    private static function dir(Company $company): array
    {
        $rel = $company->id.'/'.now('Asia/Dhaka')->format('Y-m');
        $abs = storage_path('app/staff-chat/'.$rel);
        if (! is_dir($abs)) {
            mkdir($abs, 0775, true);
        }

        return [$rel, $abs];
    }

    /** A photo is kept as a JPEG at most 1600 px on its long side (phones send 4-12 MB pictures). */
    private static function storeImage(Company $company, UploadedFile $file): array
    {
        [$rel, $abs] = static::dir($company);
        $name = Str::random(24);
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, 1600 / max($w, $h, 1));
            $img = $src;
            if ($scale < 1) {
                $img = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            }
            // transparent PNGs get a white background instead of black
            $canvas = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagejpeg($canvas, "{$abs}/{$name}.jpg", 82);

            return ['media_type' => 'image', 'media_path' => "{$rel}/{$name}.jpg", 'media_mime' => 'image/jpeg'];
        }
        $ext = $file->guessExtension() ?: 'jpg';
        $file->move($abs, "{$name}.{$ext}");

        return ['media_type' => 'image', 'media_path' => "{$rel}/{$name}.{$ext}", 'media_mime' => $file->getMimeType()];
    }

    private static function storeVoice(Company $company, UploadedFile $file, ?int $seconds): array
    {
        [$rel, $abs] = static::dir($company);
        $mime = strtolower((string) $file->getMimeType());
        $ext = static::voiceExtension($mime) ?? 'm4a';
        $name = Str::random(24).'.'.$ext;
        $file->move($abs, $name);
        $mime = ['m4a' => 'audio/mp4', 'webm' => 'audio/webm'][$ext] ?? $mime;

        return ['media_type' => 'voice', 'media_path' => "{$rel}/{$name}", 'media_mime' => $mime,
            'media_seconds' => $seconds !== null ? max(1, min(600, $seconds)) : null];
    }

    /** Push to everyone else in the chat who has not muted it: the app's notification and a browser push. */
    private static function notify(Company $company, User $user, array $chat, StaffChatMessage $m): void
    {
        [$key, $group, $other] = $chat;
        $to = $group
            ? $group->memberships()->pluck('company_user.user_id')->map(fn ($id) => (int) $id)->all()
            : [$other->id];
        $to = array_values(array_diff($to, [$user->id]));
        if (! $to) {
            return;
        }
        $muted = DB::table('staff_chat_reads')->where('company_id', $company->id)->where('chat', $key)
            ->where('muted', true)->whereIn('user_id', $to)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $to = array_values(array_diff($to, $muted));
        if (! $to) {
            return;
        }
        $title = $group ? $group->name.' · '.$user->name : $user->name;
        $text = static::preview($m);
        // a group has the same ref for everyone; one to one, each recipient sees the chat as "u{sender}"
        $ref = $group ? $key : 'u'.$user->id;
        Engine::push($company->id, $to, ['type' => 'team_chat', 'company' => $company->slug, 'chat' => $ref,
            'message_id' => $m->id, 'title' => $title, 'body' => $text],
            ['title' => $title, 'body' => $text, 'tag' => "team-{$ref}", 'url' => "/app/{$company->slug}/team-chat?chat={$ref}"]);
    }

    /** Once a day (on a send): images and voice older than KEEP_DAYS are deleted, the messages stay. */
    public static function pruneDaily(): void
    {
        if (! Cache::add('staff-chat-pruned', 1, now()->addDay())) {
            return;
        }
        static::prune();
    }

    public static function prune(): int
    {
        $n = 0;
        StaffChatMessage::whereNotNull('media_path')->whereNull('media_gone_at')
            ->where('created_at', '<', now()->subDays(static::KEEP_DAYS))->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $m) {
                    if ($path = $m->file()) {
                        @unlink($path);
                    }
                    $m->forceFill(['media_gone_at' => now()])->save();
                    $n++;
                }
            });

        return $n;
    }

    /** The JSON of one message for the app. */
    public static function message(StaffChatMessage $m, User $me, ?string $role, int $seenUpTo = 0): array
    {
        $deleted = $m->deleted_at !== null;

        return [
            'id' => $m->id,
            'sender_id' => $m->sender_id,
            'sender' => $m->sender?->name ?? __('প্রাক্তন সদস্য'),
            'mine' => $m->sender_id === $me->id,
            'text' => $deleted ? null : $m->body,
            'media' => $deleted || ! $m->media_type ? null : ['type' => $m->media_type, 'seconds' => $m->media_seconds,
                'gone' => $m->media_gone_at !== null],
            'deleted' => $deleted,
            'seen' => $m->sender_id === $me->id && $seenUpTo >= $m->id,
            'can_delete' => $m->deletableBy($me, $role),
            'at' => $m->created_at->toIso8601String(),
        ];
    }
}
