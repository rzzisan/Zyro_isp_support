<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\WalkieCall;
use App\Models\WalkieClip;
use App\Models\WalkieGroup;
use App\Services\Engine;
use App\Support\Walkie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Android app walkie-talkie: the member's groups (with tokens for the LiveKit rooms), each group's recorded
 * transmissions, and private calls between two members of a group (ringing by push, like a WhatsApp call).
 */
class WalkieController extends Controller
{
    private function group(Request $request, Company $company, int $id): WalkieGroup
    {
        abort_unless(Walkie::configured(), 404);
        $g = WalkieGroup::where('company_id', $company->id)->findOrFail($id);
        abort_unless(Walkie::memberOf($request->user(), $g), 403);

        return $g;
    }

    /** {url, on_at, off_at, groups: [{id, name, room, token, members: [{id, name}]}]}: one token per group. */
    public function index(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        $groups = Walkie::groupsFor($user, $company)->load('memberships.user');
        [$on, $off] = Walkie::hours($company->id);

        return response()->json([
            'url' => config('services.livekit.url'),
            'me' => $user->id,
            'on_at' => $on,
            'off_at' => $off,
            'groups' => $groups->map(fn (WalkieGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'room' => $g->room(), 'token' => Walkie::token($user, $g->room()),
                'members' => $g->memberships->map(fn ($m) => $m->user)->filter()->sortBy('name')
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /** The group's recorded transmissions, newest first, 30 a page. */
    public function clips(Request $request, Company $company, int $group): JsonResponse
    {
        $g = $this->group($request, $company, $group);
        $page = max(1, $request->integer('page', 1));
        $rows = WalkieClip::where('walkie_group_id', $g->id)->with('user:id,name')
            ->orderByDesc('started_at')->orderByDesc('id')->forPage($page, 30)->get();

        return response()->json([
            'data' => $rows->map(fn (WalkieClip $c) => ['id' => $c->id, 'user' => $c->user?->name, 'user_id' => $c->user_id,
                'started_at' => $c->started_at->toIso8601String(), 'seconds' => $c->seconds])->values(),
            'next_page' => $rows->count() === 30 ? $page + 1 : null,
        ]);
    }

    public function clip(Request $request, Company $company, WalkieClip $clip): BinaryFileResponse
    {
        abort_unless($clip->company_id === $company->id && $clip->walkie_group_id, 404);
        $this->group($request, $company, $clip->walkie_group_id);
        $path = $clip->file();
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    private function callRow(WalkieCall $c, User $me): array
    {
        $other = $c->caller_id === $me->id ? $c->callee : $c->caller;

        return ['id' => $c->id, 'status' => $c->status, 'room' => $c->room(), 'outgoing' => $c->caller_id === $me->id,
            'other' => $other ? ['id' => $other->id, 'name' => $other->name] : null,
            'url' => config('services.livekit.url'),
            'token' => $c->open() ? Walkie::token($me, $c->room(), 3600) : null];
    }

    private function call(Request $request, Company $company, int $id): WalkieCall
    {
        abort_unless(Walkie::configured(), 404);
        $c = WalkieCall::where('company_id', $company->id)->findOrFail($id);
        abort_unless(in_array($request->user()->id, [$c->caller_id, $c->callee_id], true), 403);

        return $c->expire();
    }

    /** Ring a member who shares a group with the caller: {user_id}. */
    public function startCall(Request $request, Company $company): JsonResponse
    {
        abort_unless(Walkie::configured(), 404);
        $me = $request->user();
        $to = (int) $request->input('user_id');
        abort_if($to === $me->id, 422);
        abort_unless(Walkie::share($company, $me->id, $to), 403);
        // whatever either of them still had ringing is over now
        WalkieCall::where('company_id', $company->id)->where('status', 'ringing')
            ->where(fn ($q) => $q->whereIn('caller_id', [$me->id, $to])->orWhereIn('callee_id', [$me->id, $to]))
            ->update(['status' => 'missed', 'ended_at' => now()]);
        $c = WalkieCall::create(['company_id' => $company->id, 'caller_id' => $me->id, 'callee_id' => $to, 'status' => 'ringing']);
        Engine::push($company->id, [$to], ['type' => 'walkie_call', 'company' => $company->slug, 'call_id' => $c->id, 'name' => $me->name]);

        return response()->json($this->callRow($c, $me));
    }

    public function showCall(Request $request, Company $company, int $call): JsonResponse
    {
        return response()->json($this->callRow($this->call($request, $company, $call), $request->user()));
    }

    public function answer(Request $request, Company $company, int $call): JsonResponse
    {
        $c = $this->call($request, $company, $call);
        abort_unless($c->callee_id === $request->user()->id, 403);
        abort_unless($c->status === 'ringing', 409, __('কলটা আর নেই'));
        $c->update(['status' => 'talking', 'answered_at' => now()]);

        return response()->json($this->callRow($c, $request->user()));
    }

    /** Hang up, decline or cancel: the other phone stops ringing or leaves the call. */
    public function end(Request $request, Company $company, int $call): JsonResponse
    {
        $c = $this->call($request, $company, $call);
        $me = $request->user();
        if ($c->open()) {
            $status = $c->status === 'talking' ? 'ended' : ($c->callee_id === $me->id ? 'declined' : 'missed');
            $c->update(['status' => $status, 'ended_at' => now()]);
            $other = $c->caller_id === $me->id ? $c->callee_id : $c->caller_id;
            if ($other) {
                Engine::push($company->id, [$other], ['type' => 'walkie_call_end', 'company' => $company->slug, 'call_id' => $c->id]);
            }
        }

        return response()->json($this->callRow($c, $me));
    }
}
