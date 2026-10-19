<?php

namespace App\Http\Controllers\Api;

use App\Filament\App\TicketActions;
use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Android app: billing tickets (members with the tickets menu). The list is the Desk's copy; new, assign and solve go
 * to the billing software through the engine, as in the panel. Customer SMS goes from the Desk gateway, never billing's.
 */
class TicketController extends Controller
{
    private const TABS = ['open' => ['pending', 'processing'], 'pending' => ['pending'], 'processing' => ['processing'],
        'solved' => ['solved', 'closed']];

    private function allow(Request $request, Company $company): void
    {
        abort_unless(Menu::allows($request->user(), $company, 'tickets'), 403);
    }

    private function ticket(Company $company, int $id): BillingTicket
    {
        return BillingTicket::where('company_id', $company->id)->findOrFail($id);
    }

    public static function row(BillingTicket $t): array
    {
        return [
            'id' => $t->id,
            'complain_id' => (string) $t->complain_id,
            'customer_name' => $t->customer_name,
            'customer_id' => $t->customer_id,
            'header_id' => $t->customer_header_id ? (int) $t->customer_header_id : null,
            'username' => $t->username,
            'mobile' => $t->mobile,
            'complain_number' => $t->complainNumber(),
            'zone' => $t->zone,
            'subzone' => $t->subzone,
            'category' => $t->category,
            'priority' => $t->priority,
            'state' => $t->state,
            'person' => $t->solved_by ?? $t->assigned_to,
            'description' => $t->description,
            'created_by' => $t->created_by,
            'opened_at' => $t->opened_at?->format(DATE_ATOM),
            'solved_at' => $t->solved_at?->format(DATE_ATOM),
            'duration' => $t->duration(),
            'open' => $t->isOpen(),
        ];
    }

    /** ?tab= open (default), pending, processing, solved, all; ?q= ticket no, name, ID, mobile, PPPoE; ?page=. */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $tab = (string) $request->query('tab', 'open');
        $q = trim((string) $request->query('q'));
        $page = max(1, $request->integer('page', 1));
        $rows = BillingTicket::where('company_id', $company->id)
            ->when(self::TABS[$tab] ?? null, fn ($w, $states) => $w->whereIn('state', $states))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('complain_id', ltrim($q, '#'))
                ->orWhere('customer_name', 'ilike', "%{$q}%")->orWhere('customer_id', $q)
                ->orWhere('mobile', 'like', "%{$q}%")->orWhere('username', 'ilike', "%{$q}%")))
            ->orderByDesc('opened_at')->orderByDesc('id')->forPage($page, 50)->get();
        $count = fn (array $states) => BillingTicket::where('company_id', $company->id)->whereIn('state', $states)->count();
        $at = BillingTicket::where('company_id', $company->id)->max('synced_at');

        return response()->json([
            'data' => $rows->map(fn ($t) => self::row($t))->values(),
            'next_page' => $rows->count() === 50 ? $page + 1 : null,
            'counts' => ['open' => $count(['pending', 'processing']), 'pending' => $count(['pending']),
                'processing' => $count(['processing'])],
            'synced_at' => $at ? \Illuminate\Support\Carbon::parse($at, 'UTC')->format(DATE_ATOM) : null,
        ]);
    }

    /** One ticket and the customer's line now (MikroTik, OLT/ONU, bill), or why the line could not be read. */
    public function show(Request $request, Company $company, int $ticket): JsonResponse
    {
        $this->allow($request, $company);
        $t = $this->ticket($company, $ticket);
        [$info, $error] = TicketActions::liveInfo((int) $t->customer_header_id, $request->boolean('fresh'), $company->id);

        return response()->json(['ticket' => self::row($t), 'live' => $info, 'live_error' => $error]);
    }

    /** The billing lists for the forms, the SMS defaults, and (with ?header_id=) that customer's open ticket. */
    public function options(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $sms = SmsSetting::forCompany($company->id);
        $headerId = $request->integer('header_id');
        $pick = fn (string $key) => collect(TicketActions::options($key, $company->id))
            ->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values();
        $priorities = $pick('priorities');

        return response()->json([
            'categories' => $pick('categories'),
            'priorities' => $priorities->isNotEmpty() ? $priorities
                : collect(['1' => 'Low', '2' => 'Medium', '3' => 'High'])->map(fn ($n, $id) => ['id' => (string) $id, 'name' => $n])->values(),
            'departments' => $pick('departments'),
            'employees' => $pick('employees'),
            'sms_open' => ['ready' => $sms->eventReady('ticket_open'), 'on' => $sms->eventOn('ticket_open')],
            'sms_solved' => ['ready' => $sms->eventReady('ticket_solved'), 'on' => $sms->eventOn('ticket_solved')],
            'customer' => $headerId && ($c = BillingCustomer::where('company_id', $company->id)->where('header_id', $headerId)->first())
                ? CustomerController::row($c) : null,
            'open_ticket' => $headerId ? BillingTicket::where('company_id', $company->id)->where('customer_header_id', $headerId)
                ->whereIn('state', ['pending', 'processing'])->orderByDesc('id')->value('complain_id') : null,
        ]);
    }

    /** Customer search for the new-ticket form (members with tickets but not the customers menu use it too). */
    public function customers(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $rows = BillingCustomer::headerSearch($company->id, (string) $request->query('q'))->where('is_left', false);

        return response()->json(['data' => $rows->map(fn ($c) => CustomerController::row($c))->values()]);
    }

    /** A new ticket in the billing software for a customer (header_id), like the panel's "নতুন টিকিট". */
    public function store(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $data = $request->validate([
            'header_id' => ['required', 'integer'],
            'category_id' => ['required', 'string'],
            'priority' => ['required', 'integer'],
            'mobile' => ['required', 'string', 'max:30'],
            'comment' => ['required', 'string', 'max:1900'],
            'dept_id' => ['nullable', 'string'],
            'employees' => ['array'],
            'employees.*' => ['string'],
            'sms_client' => ['boolean'],
        ]);
        $c = BillingCustomer::where('company_id', $company->id)->where('header_id', $data['header_id'])->firstOrFail();
        $employees = array_values($data['employees'] ?? []);
        try {
            $res = Engine::createTicket($company->id, [
                'header_id' => (int) $c->header_id, 'username' => $c->username,
                'category_id' => $data['category_id'], 'priority' => (int) $data['priority'],
                'mobile' => $data['mobile'], 'comment' => $data['comment'],
                'sms_client' => false,  // never billing's SMS
                'employees' => $employees, 'dept_id' => $data['dept_id'] ?? null, 'sms_employees' => false,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $sms = ($data['sms_client'] ?? false) ? TicketActions::queueTicketSms('ticket_open', (int) $c->header_id, $data['mobile'], [
            'ticket_no' => (string) ($res['complain_id'] ?? ''),
            'problem' => TicketActions::options('categories', $company->id)[$data['category_id']] ?? '',
            'status' => 'খোলা', 'employees' => $employees,
        ], $company->id) : null;

        return response()->json(['complain_id' => $res['complain_id'] ?? null, 'message' => $res['message'] ?? null, 'sms' => $sms]);
    }

    /** Give an open ticket to billing employees (and optionally a department). */
    public function assign(Request $request, Company $company, int $ticket): JsonResponse
    {
        $this->allow($request, $company);
        $t = $this->ticket($company, $ticket);
        abort_unless($t->isOpen(), 422, __('টিকিটটা আর খোলা নেই'));
        $data = $request->validate(['employees' => ['required', 'array', 'min:1'], 'employees.*' => ['string'],
            'dept_id' => ['nullable', 'string']]);
        try {
            Engine::assign($company->id, $t->complain_id, array_values($data['employees']), $data['dept_id'] ?? null, false);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Solve in billing; the engine checks again that the customer is online on MikroTik. */
    public function solve(Request $request, Company $company, int $ticket): JsonResponse
    {
        $this->allow($request, $company);
        $t = $this->ticket($company, $ticket);
        abort_unless($t->isOpen(), 422, __('টিকিটটা আর খোলা নেই'));
        $data = $request->validate(['remark' => ['nullable', 'string', 'max:500'], 'sms' => ['boolean']]);
        try {
            Engine::solve($company->id, $t->complain_id, $data['remark'] ?? null);
        } catch (RuntimeException $e) {
            Cache::forget('ticket-info:'.$company->id.':'.(int) $t->customer_header_id);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $sms = ($data['sms'] ?? false) ? TicketActions::queueTicketSms('ticket_solved', (int) $t->customer_header_id, $t->mobile, [
            'ticket_no' => (string) $t->complain_id, 'problem' => (string) $t->category, 'status' => 'সমাধান',
            'technician' => (string) ($t->solved_by ?: $t->assigned_to)], $company->id) : null;

        return response()->json(['ok' => true, 'sms' => $sms]);
    }
}
