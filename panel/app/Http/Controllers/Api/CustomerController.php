<?php

namespace App\Http\Controllers\Api;

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

    /** Header search with ?q= (like the panel's); without q, a page of current customers (?due=1, ?zone=, ?page=). */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $rows = BillingCustomer::headerSearch($company->id, $q);

            return response()->json(['data' => $this->withOnline($company, $rows), 'next_page' => null]);
        }
        $page = $request->integer('page', 1);
        $rows = BillingCustomer::where('company_id', $company->id)->whereNull('gone_at')->where('is_left', false)
            ->when($request->boolean('due'), fn ($w) => $w->where('due', '>', 0))
            ->when($request->query('zone'), fn ($w, $z) => $w->where('zone', $z))
            ->orderByRaw("CASE WHEN customer_id ~ '^[0-9]{1,9}$' THEN customer_id::bigint END NULLS LAST")->orderBy('customer_id')
            ->forPage($page, 50)->get();

        return response()->json(['data' => $this->withOnline($company, $rows), 'next_page' => $rows->count() === 50 ? $page + 1 : null]);
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
