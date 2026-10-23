<?php

namespace App\Http\Controllers\Api;

use App\Filament\App\TicketActions;
use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\FieldJob;
use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\FieldWork;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Android app, "আমার কাজ" for field technicians (field_jobs menu): the tickets naming the member's billing employee,
 * start / pause / resume the work with a target time, the customer's MikroTik line every minute, and finishing the work
 * (solve in billing) only when the line is online.
 */
class FieldJobController extends Controller
{
    private function allow(Request $request, Company $company): void
    {
        abort_unless(FieldWork::allowed($request->user(), $company), 403);
    }

    /** One of the member's tickets (404 for anyone else's). */
    private function ticket(Request $request, Company $company, int $id): BillingTicket
    {
        $this->allow($request, $company);

        return FieldWork::tickets($request->user(), $company)->findOrFail($id);
    }

    public static function job(?FieldJob $j): ?array
    {
        if (! $j) {
            return null;
        }

        return [
            'id' => $j->id,
            'status' => $j->status,
            'started_at' => $j->started_at?->toIso8601String(),
            'started_by' => $j->starter?->name,
            'paused_at' => $j->paused_at?->toIso8601String(),
            'pause_reason' => $j->status === 'paused' ? $j->pause_reason : null,
            'worked_seconds' => $j->workedSeconds(),
            'target_minutes' => $j->target_minutes,
            'line_check' => $j->line_check,
            'line_online' => $j->line_online,
            'line_checked_at' => $j->line_checked_at?->toIso8601String(),
            'finished_at' => $j->finished_at?->toIso8601String(),
            'force_reason' => $j->force_reason,
        ];
    }

    private function row(BillingTicket $t, ?FieldJob $j): array
    {
        return TicketController::row($t) + ['job' => self::job($j)];
    }

    /** The member's open tickets (newest first) and the ones solved in the last day. */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        FieldWork::closeSolved($company->id);
        $tickets = FieldWork::tickets($request->user(), $company)->orderByDesc('opened_at')->orderByDesc('id')->limit(100)->get();
        $jobs = FieldJob::whereIn('ticket_id', $tickets->pluck('id'))->with('starter')->get()->keyBy('ticket_id');
        $online = BillingCustomer::where('company_id', $company->id)->whereIn('header_id', $tickets->pluck('customer_header_id')->filter())
            ->get()->mapWithKeys(fn (BillingCustomer $c) => [(int) $c->header_id => (bool) $c->onlineSession()]);

        return response()->json([
            'data' => $tickets->map(fn ($t) => $this->row($t, $jobs[$t->id] ?? null)
                + ['online' => $online[(int) $t->customer_header_id] ?? null])->values(),
            'linked' => FieldWork::employeeName($request->user(), $company) !== null,
        ]);
    }

    /** One ticket with its job, the customer (and their chat) and the line box (MikroTik, OLT/ONU, bill). */
    public function show(Request $request, Company $company, int $ticket): JsonResponse
    {
        $t = $this->ticket($request, $company, $ticket);
        $job = FieldJob::where('ticket_id', $t->id)->with('starter')->first();
        $c = $t->customer_header_id ? BillingCustomer::where('company_id', $company->id)->where('header_id', $t->customer_header_id)->first() : null;
        [$info, $error] = TicketActions::liveInfo((int) $t->customer_header_id, $request->boolean('fresh'), $company->id);
        [$minutes, $check] = FieldWork::target($company->id, $t->category);
        $sms = SmsSetting::forCompany($company->id);

        return response()->json([
            'ticket' => $this->row($t, $job),
            'customer' => $c ? CustomerController::row($c, (bool) $c->onlineSession()) + ['address' => $c->address, 'chat_id' => $this->chatId($request, $company, $c)] : null,
            'live' => $info, 'live_error' => $error,
            'target_minutes' => $job?->target_minutes ?? $minutes,
            'line_check' => $job?->line_check ?? $check,
            'can_force' => Menu::allows($request->user(), $company, 'force_solve'),
            'sms_solved' => ['ready' => $sms->eventReady('ticket_solved'), 'on' => $sms->eventOn('ticket_solved')],
        ]);
    }

    /** The customer's WhatsApp chat when it exists and this member may open it now (a technician: while the work runs). */
    private function chatId(Request $request, Company $company, BillingCustomer $c): ?int
    {
        $chat = $c->chat();
        if (! $chat) {
            return null;
        }
        $user = $request->user();
        $open = FieldWork::restricted($user, $company) || ! Menu::allows($user, $company, 'inbox')
            ? FieldWork::mayChat($user, $company, $chat) : true;

        return $open ? $chat->id : null;
    }

    public function start(Request $request, Company $company, int $ticket): JsonResponse
    {
        $t = $this->ticket($request, $company, $ticket);
        abort_unless($t->isOpen(), 422, __('টিকিটটা আর খোলা নেই'));
        $job = FieldJob::where('ticket_id', $t->id)->first();
        if ($job && $job->status !== 'done') {
            return response()->json(['job' => self::job($job->load('starter'))]);   // a team mate started it already
        }
        [$minutes, $check] = FieldWork::target($company->id, $t->category);
        $job = FieldJob::updateOrCreate(['ticket_id' => $t->id], ['company_id' => $company->id, 'status' => 'running',
            'started_by' => $request->user()->id, 'started_at' => now(), 'paused_at' => null, 'paused_seconds' => 0, 'pause_reason' => null,
            'target_minutes' => $minutes, 'line_check' => $check, 'finished_at' => null, 'finished_by' => null, 'force_reason' => null]);
        $job->log('start', $request->user()->id);
        FieldWork::place($job->setRelation('ticket', $t), 'start', FieldWork::point($request));

        return response()->json(['job' => self::job($job->load('starter'))]);
    }

    public function pause(Request $request, Company $company, int $ticket): JsonResponse
    {
        $t = $this->ticket($request, $company, $ticket);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $job = FieldJob::where('ticket_id', $t->id)->where('status', 'running')->firstOrFail();
        $job->forceFill(['status' => 'paused', 'paused_at' => now(), 'pause_reason' => $data['reason']])->save();
        $job->log('pause', $request->user()->id, $data['reason']);

        return response()->json(['job' => self::job($job->load('starter'))]);
    }

    public function resume(Request $request, Company $company, int $ticket): JsonResponse
    {
        $t = $this->ticket($request, $company, $ticket);
        $job = FieldJob::where('ticket_id', $t->id)->where('status', 'paused')->firstOrFail();
        $job->forceFill(['status' => 'running', 'paused_seconds' => $job->paused_seconds + (int) $job->paused_at->diffInSeconds(now()),
            'paused_at' => null])->save();
        $job->log('resume', $request->user()->id);

        return response()->json(['job' => self::job($job->load('starter'))]);
    }

    /** The customer's PPPoE on MikroTik (the app asks every minute while the job is open; ?fresh=1 asks the router now). */
    public function line(Request $request, Company $company, int $ticket): JsonResponse
    {
        $t = $this->ticket($request, $company, $ticket);
        abort_unless($t->customer_header_id, 404);
        $line = FieldWork::line($company->id, (int) $t->customer_header_id, $request->boolean('fresh'));
        if ($job = FieldJob::where('ticket_id', $t->id)->where('status', '!=', 'done')->first()) {
            FieldWork::noteLine($job, $line);
        }

        return response()->json($line);
    }

    /** Finish: the work must have been started, and the line must be online (or a force_solve member's reason). */
    public function solve(Request $request, Company $company, int $ticket): JsonResponse
    {
        $t = $this->ticket($request, $company, $ticket);
        abort_unless($t->isOpen(), 422, __('টিকিটটা আর খোলা নেই'));
        $data = $request->validate(['remark' => ['nullable', 'string', 'max:500'], 'sms' => ['boolean'],
            'force_reason' => ['nullable', 'string', 'max:500']]);
        $job = FieldJob::where('ticket_id', $t->id)->where('status', '!=', 'done')->first();
        abort_unless($job, 422, __('আগে "কাজ শুরু করুন" চাপুন'));
        $force = trim((string) ($data['force_reason'] ?? '')) ?: null;
        if ($why = FieldWork::solveBlock($request->user(), $company, $t, $force, $job)) {
            return response()->json(['message' => $why, 'line' => $job->line_online], 422);
        }
        $online = $job->line_check ? (bool) $job->fresh()->line_online : null;
        try {
            // the engine checks the line again, unless this category needs no line or a reason was accepted above
            Engine::solve($company->id, $t->complain_id, $data['remark'] ?? null, $online !== true);
        } catch (RuntimeException $e) {
            Cache::forget('ticket-info:'.$company->id.':'.(int) $t->customer_header_id);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $point = FieldWork::point($request);
        FieldWork::place($job->setRelation('ticket', $t), 'finish', $point);
        FieldWork::solved($t, $request->user()->id, $online, $online === false ? $force : null);
        FieldWork::learnCustomer($job->fresh()->setRelation('ticket', $t), $point);
        $sms = ($data['sms'] ?? false) ? TicketActions::queueTicketSms('ticket_solved', (int) $t->customer_header_id, $t->mobile, [
            'ticket_no' => (string) $t->complain_id, 'problem' => (string) $t->category, 'status' => 'সমাধান',
            'technician' => (string) ($t->solved_by ?: $t->assigned_to)], $company->id) : null;

        return response()->json(['ok' => true, 'sms' => $sms]);
    }
}
