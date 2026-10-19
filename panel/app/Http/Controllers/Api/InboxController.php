<?php

namespace App\Http\Controllers\Api;

use App\Filament\App\Resources\Conversations\Pages\ViewConversation;
use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\Technician;
use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\WhatsApp;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Android app: the WhatsApp inbox (members with the inbox menu), the same chats and actions as the panel's. */
class InboxController extends Controller
{
    private function allow(Request $request, Company $company): void
    {
        abort_unless(Menu::allows($request->user(), $company, 'inbox'), 403);
    }

    private function contact(Company $company, int $id): WaContact
    {
        return WaContact::where('company_id', $company->id)->findOrFail($id);
    }

    private static function at(?\DateTimeInterface $at): ?string
    {
        return $at?->format(DATE_ATOM);
    }

    private static function chat(WaContact $c, bool $staff): array
    {
        $m = $c->lastMessage;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'number' => $c->displayNumber(),
            'customer_id' => $c->customer_id,
            'last_message_at' => self::at($c->last_message_at),
            'last_message' => $m ? ['type' => $m->type, 'body' => $m->body, 'direction' => $m->direction, 'sender' => $m->sender] : null,
            'waiting' => $m?->direction === 'in' && ! $staff,
            'staff' => $staff,
            'assigned' => $c->assignedUser ? ['id' => $c->assignedUser->id, 'name' => $c->assignedUser->name] : null,
            'bot_paused' => $c->isBotPaused(),
            'bot_paused_until' => $c->bot_paused ? null : self::at($c->bot_paused_until?->isFuture() ? $c->bot_paused_until : null),
            'notify_muted' => (bool) $c->notify_muted,
        ];
    }

    private static function message(WaMessage $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'sender' => $m->sender,
            'user' => $m->user?->name,
            'type' => $m->type,
            'body' => $m->body,
            'has_media' => $m->hasMedia(),
            'media_mime' => $m->media_mime,
            'status' => $m->status,
            'at' => self::at($m->created_at),
        ];
    }

    /** ?box= (all, waiting, mine, paused, staff, muted), ?q= name / customer ID / number. */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $chats = WaContact::inbox($company->id, (string) $request->query('box', 'all'), (string) $request->query('q', ''),
            $request->user()->id)->limit(60)->get();
        $staff = Technician::where('company_id', $company->id)->whereIn('wa_number', $chats->pluck('wa_number'))
            ->pluck('wa_number')->flip();

        return response()->json([
            'data' => $chats->map(fn ($c) => self::chat($c, $staff->has($c->wa_number)))->values(),
            'waiting_count' => WaContact::waitingCount($company->id),
        ]);
    }

    /** One chat: the latest 150 messages oldest first, whether a free reply is allowed, and who it can be given to. */
    public function show(Request $request, Company $company, int $contact): JsonResponse
    {
        $this->allow($request, $company);
        $c = $this->contact($company, $contact)->load(['lastMessage', 'assignedUser']);
        $messages = $c->messages()->with('user')->latest('id')->limit(150)->get()->reverse()->values();
        $staff = Technician::where('company_id', $company->id)->where('wa_number', $c->wa_number)->exists();
        $cid = $c->suggestedCustomerId();  // the line this chat is about, for the app's customer and ticket buttons
        $line = $cid ? BillingCustomer::where('company_id', $company->id)->whereNull('gone_at')->where('customer_id', $cid)->first() : null;

        return response()->json([
            'chat' => self::chat($c, $staff) + ['window_open' => $c->windowOpen(), 'sending' => WhatsApp::sendingEnabled(),
                'header_id' => $line ? (int) $line->header_id : null, 'line_customer_id' => $line?->customer_id],
            'messages' => $messages->map(fn ($m) => self::message($m)),
            'members' => $company->users()->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    /** A staff reply: text, or a file (image, PDF, MP4, MP3) with an optional caption; the bot may stay quiet a while. */
    public function reply(Request $request, Company $company, int $contact): JsonResponse
    {
        $this->allow($request, $company);
        $c = $this->contact($company, $contact);
        $data = $request->validate([
            'body' => [$request->hasFile('file') ? 'nullable' : 'required', 'string', 'max:4000'],
            'pause_minutes' => ['nullable', 'integer', 'min:0', 'max:4320'],
            'file' => ['nullable', 'file', 'max:12288', 'mimetypes:'.implode(',', array_keys(WhatsApp::MEDIA_TYPES))],
        ], [
            'body.required' => __('মেসেজ লিখুন'),
            'file.max' => __('ফাইল ১২ MB-এর বেশি হতে পারবে না'),
            'file.mimetypes' => __('শুধু ছবি (JPG/PNG/WEBP), PDF, MP4 বা MP3 পাঠানো যায়'),
        ]);
        $pause = (int) ($data['pause_minutes'] ?? 0);
        try {
            $file = $request->file('file');
            $m = $file
                ? WhatsApp::sendMedia($c, $file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName(),
                    $data['body'] ?? null, $request->user(), $pause)
                : WhatsApp::sendText($c, $data['body'], $request->user(), $pause);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => self::message($m->load('user'))]);
    }

    /** Give the chat to a member (user_id), to yourself (user_id = "me"), or to nobody (null). */
    public function assign(Request $request, Company $company, int $contact): JsonResponse
    {
        $this->allow($request, $company);
        $c = $this->contact($company, $contact);
        $id = $request->input('user_id');
        $id = $id === 'me' ? $request->user()->id : ($id === null ? null : (int) $id);
        abort_if($id && ! $company->users()->whereKey($id)->exists(), 422, __('এই সদস্য এই কোম্পানিতে নেই'));
        $c->forceFill(['assigned_user_id' => $id])->save();

        return $this->chatResponse($c);
    }

    /** paused = true stops the bot on this number for 12 hours (as in the panel); false lets it answer again. */
    public function bot(Request $request, Company $company, int $contact): JsonResponse
    {
        $this->allow($request, $company);
        $c = $this->contact($company, $contact);
        $request->validate(['paused' => ['required', 'boolean']]);
        $request->boolean('paused') ? $c->pauseBot(ViewConversation::MANUAL_PAUSE_HOURS) : $c->resumeBot();

        return $this->chatResponse($c);
    }

    /** muted = true: messages from this number ring no bell or push for anyone; the chat and the bot are unchanged. */
    public function mute(Request $request, Company $company, int $contact): JsonResponse
    {
        $this->allow($request, $company);
        $c = $this->contact($company, $contact);
        $request->validate(['muted' => ['required', 'boolean']]);
        $c->forceFill(['notify_muted' => $request->boolean('muted')])->save();

        return $this->chatResponse($c);
    }

    /** Voice, image or file of a message, fetched from Meta once and kept on the server. */
    public function media(Request $request, Company $company, int $message): BinaryFileResponse
    {
        $this->allow($request, $company);
        $m = WaMessage::where('company_id', $company->id)->findOrFail($message);
        abort_unless($m->hasMedia(), 404);
        try {
            [$path, $mime] = WhatsApp::media($m);
        } catch (\Throwable $e) {
            report($e);
            abort(404, __('মিডিয়া পাওয়া যায়নি (Meta থেকে মুছে গেছে বা মেয়াদ শেষ)'));
        }

        return response()->file($path, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=86400']);
    }

    private function chatResponse(WaContact $c): JsonResponse
    {
        $c->refresh()->load(['lastMessage', 'assignedUser']);
        $staff = Technician::where('company_id', $c->company_id)->where('wa_number', $c->wa_number)->exists();

        return response()->json(['chat' => self::chat($c, $staff)]);
    }
}
