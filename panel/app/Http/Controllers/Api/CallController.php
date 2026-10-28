<?php

namespace App\Http\Controllers\Api;

use App\Filament\App\Pages\CallLogs;
use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\CallLog;
use App\Models\Company;
use App\Models\Technician;
use App\Models\WaContact;
use App\Support\Menu;
use App\Support\Softphone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Android app: the hotline call log, like the panel's "কল লগ" page; bot-answered calls have a recording and a transcript. */
class CallController extends Controller
{
    private function allow(Request $request, Company $company): void
    {
        abort_unless(Menu::allows($request->user(), $company, 'calls'), 403);
        abort_unless(CallLog::enabledFor($company->id), 404, __('হটলাইন সেট করা নেই'));
    }

    public static function row(CallLog $c): array
    {
        $customer = $c->customers(1)->first();

        return [
            'id' => $c->id,
            'caller' => $c->caller_normalized ? '0'.substr($c->caller_normalized, 3) : $c->caller,
            'started_at' => $c->started_at?->toIso8601String(),
            'ended_at' => $c->ended_at?->toIso8601String(),
            'channel' => $c->channel ?? 'hotline',
            'direction' => $c->direction ?? 'in',
            'answered_by' => $c->answeredBy?->name,
            'agent_seconds' => $c->agent_seconds === null ? null : (int) $c->agent_seconds,
            'ringing' => $c->status === 'ringing',
            'bot_answered' => (bool) $c->bot_answered,
            'bot_seconds' => $c->bot_seconds === null ? null : (int) $c->bot_seconds,
            'flag' => $c->bot_flag ? __(CallLogs::FLAGS[$c->bot_flag] ?? $c->bot_flag) : null,
            'callback_reason' => $c->callback_reason,
            'outcome' => $c->outcome,
            'outcome_label' => $c->outcome ? __(CallLogs::OUTCOMES[$c->outcome][0] ?? $c->outcome) : null,
            'outcome_text' => $c->outcome_text,
            'has_recording' => filled($c->recording_path),
            'customer' => $customer ? ['header_id' => (int) $customer->header_id, 'customer_id' => $customer->customer_id,
                'name' => $customer->name, 'left' => (bool) $customer->is_left] : null,
        ];
    }

    /** Newest first, 30 a page; ?who=bot|agent, ?callback=1, ?q= (the caller's number). */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $page = max(1, $request->integer('page', 1));
        $q = CallLog::where('company_id', $company->id);
        match ($request->query('who')) {
            'bot' => $q->where('bot_answered', true),
            'agent' => $q->where('bot_answered', false),
            default => null,
        };
        if ($request->boolean('callback')) {
            $q->whereNotNull('callback_reason');
        }
        if (($digits = preg_replace('/\D/', '', (string) $request->query('q'))) !== '') {
            $q->where(fn ($w) => $w->where('caller', 'like', "%{$digits}%")
                ->orWhere('caller_normalized', 'like', '%'.ltrim($digits, '0').'%'));
        }
        $rows = $q->orderByDesc('started_at')->orderByDesc('id')->forPage($page, 30)->get();

        return response()->json(['data' => $rows->map(fn (CallLog $c) => self::row($c))->values(),
            'next_page' => $rows->count() === 30 ? $page + 1 : null]);
    }

    /** One call with what each side said ([{who: bot|caller, text, at}]). */
    public function show(Request $request, Company $company, int $call): JsonResponse
    {
        $this->allow($request, $company);
        $c = CallLog::where('company_id', $company->id)->findOrFail($call);

        return response()->json(['call' => self::row($c) + [
            'transcript' => collect($c->transcript ?? [])->map(fn ($t) => ['who' => $t['who'] ?? '', 'text' => (string) ($t['text'] ?? ''),
                'at' => (int) ($t['at'] ?? 0)])->values(),
            'bot_error' => $c->bot_error,
            'actions' => collect($c->actions ?? [])->map(fn ($a) => ['at' => (int) ($a['at'] ?? 0), 'kind' => (string) ($a['kind'] ?? ''),
                'text' => (string) ($a['text'] ?? ''), 'ok' => (bool) ($a['ok'] ?? true)])->values(),
        ]]);
    }

    /** Desk softphone login for the app's call screen (WebView of public/softphone/phone.html). */
    public function softphone(Request $request, Company $company): JsonResponse
    {
        $cfg = Softphone::config($request->user(), $company);
        abort_unless($cfg, 403);

        return response()->json($cfg)->header('Cache-Control', 'no-store');
    }

    /**
     * The app's call screen: who this number is (customers with this mobile, and the line a WhatsApp chat named) with
     * their open tickets. The live MikroTik/ONU box comes from customers/{headerId}/live.
     */
    public function callInfo(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_unless(Menu::allows($user, $company, 'phone') || Menu::allows($user, $company, 'customers')
            || Menu::allows($user, $company, 'bills'), 403);
        $n = Technician::normalize((string) $request->query('number'));
        abort_if(strlen($n) < 8, 422);
        $ids = WaContact::where('company_id', $company->id)->where('wa_number', $n)->whereNotNull('customer_id')
            ->pluck('customer_id');
        $customers = BillingCustomer::where('company_id', $company->id)
            ->where(fn ($q) => $q->where('mobile_normalized', $n)->orWhereIn('customer_id', $ids))
            ->orderBy('is_left')->orderByDesc('header_id')->limit(5)->get();
        $tickets = BillingTicket::where('company_id', $company->id)->whereIn('customer_header_id', $customers->pluck('header_id'))
            ->whereIn('state', ['pending', 'processing'])->orderByDesc('opened_at')->get()->groupBy('customer_header_id');

        return response()->json([
            'lines' => $customers->map(fn (BillingCustomer $c) => [
                'customer' => CustomerController::row($c, (bool) $c->onlineSession()) + ['address' => $c->address],
                'tickets' => ($tickets->get($c->header_id) ?? collect())->take(5)->map(fn (BillingTicket $t) => [
                    'id' => $t->id, 'complain_id' => $t->complain_id, 'category' => $t->category, 'state' => $t->state,
                    'opened_at' => $t->opened_at?->toIso8601String(), 'assigned_to' => $t->assigned_to, 'note' => $t->note,
                ])->values(),
            ])->values(),
            'can' => ['ticket' => Menu::allows($user, $company, 'tickets'), 'customer' => Menu::allows($user, $company, 'customers')],
        ]);
    }

    /** The recording (both sides, mp3), written by the engine's voice bot under storage/app/call-recordings. */
    public function recording(Request $request, Company $company, int $call): BinaryFileResponse
    {
        $this->allow($request, $company);
        $c = CallLog::where('company_id', $company->id)->findOrFail($call);
        abort_unless($c->recording_path && ! str_contains($c->recording_path, '..'), 404);
        $path = storage_path('app/call-recordings/'.$c->recording_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=86400']);
    }
}
