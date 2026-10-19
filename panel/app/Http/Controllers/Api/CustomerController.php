<?php

namespace App\Http\Controllers\Api;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\BillingCustomerPayment;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\PppSession;
use App\Services\Engine;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Android app: customer search, list and one customer's page (members with the customers menu). */
class CustomerController extends Controller
{
    private const FIELDS = ['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'package',
        'monthly_bill', 'due', 'status', 'disabled', 'is_left', 'left_on', 'last_payment_date', 'bill_day'];

    private function allow(Request $request, Company $company): void
    {
        abort_unless(Menu::allows($request->user(), $company, 'customers') || Menu::allows($request->user(), $company, 'bills'), 403);
    }

    public static function row(BillingCustomer $c, bool $online = false): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = $c->{$f};
            $out[$f] = match (true) {
                $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
                in_array($f, ['monthly_bill', 'due'], true) => $v === null ? null : (float) $v,
                in_array($f, ['header_id', 'bill_day'], true) => $v === null ? null : (int) $v,
                default => $v,
            };
        }

        return $out + ['online' => $online];
    }

    /** Header search with ?q= (like the panel's); without q, a page of customers with the list's filters (see filtered()). */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $rows = BillingCustomer::headerSearch($company->id, $q);

            return response()->json(['data' => $this->withOnline($company, $rows), 'next_page' => null]);
        }
        $page = $request->integer('page', 1);
        $base = $this->filtered($request, $company);
        $total = $page === 1 ? (clone $base)->count() : null;
        if ($base->getConnection()->getDriverName() === 'pgsql') {  // numeric IDs in number order (tests run on sqlite)
            $base->orderByRaw("CASE WHEN customer_id ~ '^[0-9]{1,9}$' THEN customer_id::bigint END NULLS LAST");
        }
        $rows = $base->orderBy('customer_id')->forPage($page, 50)->get();

        return response()->json(['data' => $this->withOnline($company, $rows), 'next_page' => $rows->count() === 50 ? $page + 1 : null,
            'total' => $total]);
    }

    /**
     * The list's filters, like the panel's customer list: ?zone= &subzone= &box= &package= &status=, ?due=1|0, ?line=on|off,
     * ?online=1|0, ?exp_from=&exp_to= (bill day of month, 1-31; from > to wraps past the month's end), ?list=current|left|all.
     */
    private function filtered(Request $request, Company $company)
    {
        $q = BillingCustomer::where('company_id', $company->id)->whereNull('gone_at');
        match ($request->query('list', 'current')) {
            'left' => $q->where('is_left', true),
            'all' => null,
            default => $q->where('is_left', false),
        };
        foreach (['zone', 'subzone', 'box', 'package', 'status'] as $f) {
            if (($v = $request->query($f)) !== null && $v !== '') {
                $q->where($f, $v);
            }
        }
        if ($request->has('due')) {
            $request->boolean('due') ? $q->where('due', '>', 0) : $q->where(fn ($w) => $w->whereNull('due')->orWhere('due', '<=', 0));
        }
        if ($request->filled('line')) {
            $q->where('disabled', $request->query('line') === 'off');
        }
        if ($request->has('online')) {
            $request->boolean('online') ? $q->whereExists(CustomerResource::freshSession()) : $q->whereNotExists(CustomerResource::freshSession());
        }
        $from = $request->integer('exp_from');
        $to = $request->integer('exp_to', $from);
        if ($from >= 1 && $from <= 31 && $to >= 1 && $to <= 31) {
            $from <= $to ? $q->whereBetween('bill_day', [$from, $to])
                : $q->where(fn ($w) => $w->where('bill_day', '>=', $from)->orWhere('bill_day', '<=', $to));
        }

        return $q;
    }

    private function withOnline(Company $company, $rows): array
    {
        $names = $rows->pluck('username')->filter()->all();
        $online = $names ? PppSession::where('company_id', $company->id)->whereIn('username', $names)
            ->where('seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))->where('blocked', false)
            ->pluck('username')->flip()->all() : [];

        return $rows->map(fn (BillingCustomer $c) => static::row($c, isset($online[$c->username])))->values()->all();
    }

    /** Zones for the list filter. */
    public function zones(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);

        return response()->json(['data' => BillingCustomer::where('company_id', $company->id)->whereNull('gone_at')
            ->whereNotNull('zone')->where('zone', '!=', '')->distinct()->orderBy('zone')->pluck('zone')->values()]);
    }

    /** Choices for the list's filters: zones with their subzones and boxes (so the app can narrow them), packages, statuses. */
    public function filters(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $base = BillingCustomer::where('company_id', $company->id)->whereNull('gone_at')->where('is_left', false);
        $places = (clone $base)->select('zone', 'subzone', 'box')->distinct()->get()
            ->map(fn ($r) => ['zone' => trim((string) $r->zone), 'subzone' => trim((string) $r->subzone), 'box' => trim((string) $r->box)]);
        $list = fn (string $col) => (clone $base)->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->pluck($col)->values();

        return response()->json([
            'places' => $places->values(),
            'packages' => $list('package'),
            'statuses' => $list('status'),
        ]);
    }

    /** One customer: details, recent payments and open tickets from the Desk's copy (fast; the live part is separate). */
    public function show(Request $request, Company $company, int $headerId): JsonResponse
    {
        $this->allow($request, $company);
        $c = BillingCustomer::where('company_id', $company->id)->where('header_id', $headerId)->firstOrFail();
        $payments = BillingCustomerPayment::where('company_id', $company->id)->where('customer_header_id', $headerId)
            ->where('is_canceled', false)->orderByDesc('received_on')->orderByDesc('id')->limit(12)
            ->get(['received_on', 'bill_month', 'paid', 'discount', 'balance_due', 'method', 'received_by', 'money_receipt_no'])
            ->map(fn ($p) => ['date' => $p->received_on?->format('Y-m-d'), 'bill_month' => $p->bill_month, 'paid' => (float) $p->paid,
                'discount' => (float) $p->discount, 'due' => (float) $p->balance_due, 'method' => $p->method,
                'received_by' => $p->received_by, 'receipt_no' => $p->money_receipt_no]);
        $tickets = BillingTicket::where('company_id', $company->id)->where('customer_header_id', $headerId)
            ->whereIn('state', ['pending', 'processing'])->orderByDesc('opened_at')->limit(5)->get()
            ->map(fn (BillingTicket $t) => ['id' => $t->id, 'complain_id' => $t->complain_id, 'category' => $t->category, 'state' => $t->state,
                'opened_at' => $t->opened_at?->toIso8601String(), 'assigned_to' => $t->assigned_to, 'note' => $t->note]);

        return response()->json(['customer' => static::row($c, (bool) $c->onlineSession()) + [
            'address' => $c->address, 'joined_on' => $c->joined_on?->format('Y-m-d'), 'server' => $c->server,
            'chat_id' => $c->chat()?->id,
        ], 'payments' => $payments, 'tickets' => $tickets,
            'can' => ['bill' => Menu::allows($request->user(), $company, 'bills')]]);
    }

    /** The live box of the customer page: MikroTik now, OLT/ONU now, billing state (same as the ticket form's). */
    public function live(Request $request, Company $company, int $headerId): JsonResponse
    {
        $this->allow($request, $company);
        $key = 'ticket-info:'.$company->id.':'.$headerId;
        if ($request->boolean('fresh')) {
            Cache::forget($key);
        }
        try {
            return response()->json(Cache::remember($key, 60, fn () => Engine::ticketInfo($company->id, $headerId)));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
