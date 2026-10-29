<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\StaffChatMessage;
use App\Support\StaffChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Android app টিম চ্যাট (App\Support\StaffChat): the member's chats, one chat's messages (marks them read), sending
 * text / an image / a voice message, deleting, muting, and the image or voice file itself. A chat is "g{group}" or
 * "u{other user}".
 */
class TeamChatController extends Controller
{
    private function chat(Request $request, Company $company, string $ref): array
    {
        $chat = StaffChat::resolve($request->user(), $company, $ref);
        abort_unless($chat, 404, __('চ্যাটটা পাওয়া যায়নি'));

        return $chat;
    }

    /** {me, unread, chats: [...], members: [{id, name, role}]}: members = whom a new one-to-one chat can go to. */
    public function index(Request $request, Company $company): JsonResponse
    {
        $me = $request->user();
        $chats = StaffChat::list($me, $company);

        return response()->json([
            'me' => $me->id,
            'unread' => (int) $chats->where('muted', false)->sum('unread'),
            'chats' => $chats,
            'members' => $company->users()->where('users.id', '!=', $me->id)->orderBy('users.name')
                ->get(['users.id', 'users.name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->pivot->role]),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Messages oldest first: the latest 50, or ?after={id} the newer ones (the open chat polls this), or ?before={id}
     * 50 older ones. Opening marks the chat read.
     */
    public function show(Request $request, Company $company, string $chat): JsonResponse
    {
        $me = $request->user();
        [$key, $group, $other] = $c = $this->chat($request, $company, $chat);
        $role = StaffChat::role($me, $company);
        $q = StaffChatMessage::where('company_id', $company->id)->where('chat', $key)->with('sender:id,name');
        if ($after = $request->integer('after')) {
            $rows = $q->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        } else {
            if ($before = $request->integer('before')) {
                $q->where('id', '<', $before);
            }
            $rows = $q->orderByDesc('id')->limit(50)->get()->reverse()->values();
        }
        $latest = (int) StaffChatMessage::where('company_id', $company->id)->where('chat', $key)->max('id');
        StaffChat::markRead($me, $company, $key, $latest);
        $seen = $other ? StaffChat::seenUpTo($company, $key, $other->id) : 0;

        return response()->json([
            'chat' => ['ref' => $chat, 'kind' => $group ? 'group' : 'direct', 'title' => $group ? $group->name : $other->name,
                'members' => $group ? $group->memberships()->with('user:id,name')->get()->map(fn ($m) => $m->user?->name)->filter()->sort()->values() : [$other->name],
                'muted' => StaffChat::muted($me, $company, $key), 'seen_up_to' => $seen],
            'messages' => $rows->map(fn ($m) => StaffChat::message($m, $me, $role, $seen))->values(),
            'has_older' => ! $request->integer('after') && $rows->isNotEmpty()
                && StaffChatMessage::where('company_id', $company->id)->where('chat', $key)->where('id', '<', $rows->first()->id)->exists(),
        ])->header('Cache-Control', 'no-store');
    }

    /** body and/or file (image, or voice with kind=voice and seconds). */
    public function send(Request $request, Company $company, string $chat): JsonResponse
    {
        $c = $this->chat($request, $company, $chat);
        $voice = $request->input('kind') === 'voice';
        $data = $request->validate([
            'body' => [$request->hasFile('file') ? 'nullable' : 'required', 'string', 'max:4000'],
            'kind' => ['nullable', 'in:image,voice'],
            'seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
            'file' => ['nullable', 'file', 'max:15360',
                'mimetypes:'.implode(',', $voice ? array_keys(StaffChat::VOICE_TYPES) : StaffChat::IMAGE_TYPES)],
        ], [
            'body.required' => __('মেসেজ লিখুন'),
            'file.max' => __('ফাইল ১৫ MB-এর বেশি হতে পারবে না'),
            'file.mimetypes' => $voice ? __('এই ভয়েস ফাইল চলবে না') : __('শুধু ছবি (JPG/PNG/WEBP) পাঠানো যায়'),
        ]);
        $file = $request->file('file');
        $m = StaffChat::send($company, $request->user(), $c, $data['body'] ?? null, $file,
            $file ? ($voice ? 'voice' : 'image') : null, isset($data['seconds']) ? (int) $data['seconds'] : null);

        return response()->json(['message' => StaffChat::message($m->load('sender:id,name'), $request->user(),
            StaffChat::role($request->user(), $company))]);
    }

    /** The sender within 15 minutes, or the owner; everyone then sees "মেসেজ মুছে ফেলা হয়েছে". */
    public function delete(Request $request, Company $company, string $chat, int $message): JsonResponse
    {
        [$key] = $this->chat($request, $company, $chat);
        $m = StaffChatMessage::where('company_id', $company->id)->where('chat', $key)->findOrFail($message);
        abort_unless($m->deletableBy($request->user(), StaffChat::role($request->user(), $company)), 403,
            __('নিজের মেসেজ ১৫ মিনিটের মধ্যে মোছা যায়'));
        if ($path = $m->file()) {
            @unlink($path);
        }
        $m->forceFill(['deleted_at' => now(), 'deleted_by' => $request->user()->id, 'body' => null])->save();

        return response()->json(['deleted' => $m->id]);
    }

    /** muted = true: no push for this chat on this member's phone and browser. */
    public function mute(Request $request, Company $company, string $chat): JsonResponse
    {
        [$key] = $this->chat($request, $company, $chat);
        $request->validate(['muted' => ['required', 'boolean']]);
        StaffChat::setMuted($request->user(), $company, $key, $request->boolean('muted'));

        return response()->json(['muted' => $request->boolean('muted')]);
    }

    public function media(Request $request, Company $company, int $message): BinaryFileResponse
    {
        $m = StaffChatMessage::where('company_id', $company->id)->whereNull('deleted_at')->findOrFail($message);
        [$key] = $this->chat($request, $company, StaffChat::refOf($m->chat, $request->user()->id));
        abort_unless($key === $m->chat, 404);   // someone else's one-to-one chat
        $path = $m->file();
        abort_unless($path, 404, __('ফাইলের মেয়াদ শেষ'));

        return response()->file($path, ['Content-Type' => $m->media_mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=604800']);
    }
}
