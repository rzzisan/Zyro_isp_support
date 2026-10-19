<?php

namespace App\Http\Controllers\Api;

use App\Filament\App\BillActions;
use App\Http\Controllers\Controller;
use App\Models\BillCollection;
use App\Models\BillingDailyBill;
use App\Models\Company;
use App\Models\SmsSetting;
use App\Services\Engine;
use App\Support\Menu;
use App\Support\Receipts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Android app: bill collection, the same flow as the panel's "বিল নিন" (BillActions): billing's Receive Bill through
 * the engine with the member's own billing login, pending until approved in billing. Members with the bills menu only.
 */
class BillController extends Controller
{
    private function allow(Request $request, Company $company): void
    {
        abort_unless(Menu::allows($request->user(), $company, 'bills'), 403, __('বিল কালেকশনের অনুমতি নেই'));
    }

    private static function formKey(Company $company, Request $request, int $headerId): string
    {
        return 'bill-form:'.$company->id.':'.$request->user()->id.':'.$headerId;
    }

    /** Receive Bill form of one customer (payable, methods, Received By, pending collections, line blocked since). */
    public function form(Request $request, Company $company, int $headerId): JsonResponse
    {
        $this->allow($request, $company);
        $key = static::formKey($company, $request, $headerId);
        Cache::forget($key);
        try {
            $f = Cache::remember($key, 120, fn () => Engine::billForm($company->id, $headerId));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        $sms = SmsSetting::forCompany($company);
        // methods / received_by come as {id: name}; lists keep the billing's order for the app's pickers
        $pairs = fn ($m) => collect($m ?? [])->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values();

        return response()->json(array_merge($f, [
            'methods' => $pairs($f['methods'] ?? []), 'received_by' => $pairs($f['received_by'] ?? []),
            'sms_ready' => $sms->billSmsReady(), 'sms_default' => $sms->bill_sms && $sms->billSmsReady(),
        ]));
    }

    public function store(Request $request, Company $company, int $headerId): JsonResponse
    {
        $this->allow($request, $company);
        $data = $request->validate([
            'paid' => 'required|numeric|min:0', 'discount' => 'nullable|numeric|min:0', 'method_id' => 'required|string|max:20',
            'received_by_id' => 'required|string|max:20', 'trx_no' => ['nullable', 'string', 'max:100', 'regex:/^\S*$/'],
            'bill_month' => 'nullable|string|max:100', 'remarks' => 'nullable|string|max:500',
            'expected_payable' => 'nullable|numeric', 'next_billing_date' => 'boolean', 'desk_sms' => 'boolean',
        ]);
        $key = static::formKey($company, $request, $headerId);
        $methodName = (Cache::get($key)['methods'] ?? [])[(string) $data['method_id']] ?? null;
        try {
            $res = Engine::receiveBill($company->id, $headerId, [
                'paid' => (float) $data['paid'], 'discount' => (float) ($data['discount'] ?? 0),
                'method_id' => (string) $data['method_id'], 'received_by_id' => (string) $data['received_by_id'],
                'trx_no' => $data['trx_no'] ?? '', 'remarks' => $data['remarks'] ?? '',
                'bill_month' => $data['bill_month'] ?? null, 'sms' => false,  // never billing's SMS
                'next_billing_date' => (bool) ($data['next_billing_date'] ?? true),
                'expected_payable' => isset($data['expected_payable']) ? (float) $data['expected_payable'] : null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            Cache::forget($key);
        }
        $smsNote = ($data['desk_sms'] ?? false) ? BillActions::queueBillSms($company->id, $headerId, $data, $res, $methodName) : null;

        return response()->json(['message' => $res['message'] ?? null, 'balance' => $res['balance'] ?? null,
            'collection_id' => $res['collection_id'] ?? null, 'daily_bill_id' => $res['daily_bill_id'] ?? null, 'sms' => $smsNote]);
    }

    /**
     * The বিল কালেকশন list (billing's Daily Bill Collection, synced): what this member may see, ?from=&to= (Y-m-d,
     * Bangladesh dates, default today), ?status=active|approved|pending|deleted, ?q=, ?page=. Totals cover the whole filter.
     */
    public function dailyBills(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $m = $request->attributes->get('membership');
        $from = Carbon::parse($request->query('from') ?: now('Asia/Dhaka')->toDateString(), 'Asia/Dhaka')->startOfDay();
        $to = Carbon::parse($request->query('to') ?: $from->toDateString(), 'Asia/Dhaka')->addDay()->startOfDay();
        $q = trim((string) $request->query('q'));
        $query = BillingDailyBill::where('company_id', $company->id)->visibleTo($m)
            ->where('collected_at', '>=', $from->copy()->utc())->where('collected_at', '<', $to->copy()->utc())
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('customer_id', 'ilike', "%{$q}%")
                ->orWhere('username', 'ilike', "%{$q}%")->orWhere('customer_name', 'ilike', "%{$q}%")->orWhere('mobile', 'like', "%{$q}%")));
        match ($request->query('status', 'active')) {
            'approved' => $query->where('is_deleted', false)->where('is_approved', true),
            'pending' => $query->where('is_deleted', false)->where('is_approved', false),
            'deleted' => $query->where('is_deleted', true),
            default => $query->where('is_deleted', false),
        };
        $totals = (clone $query)->toBase()->selectRaw('count(*) AS n, coalesce(sum(receive_amount), 0) AS paid,
            coalesce(sum(discount_amount), 0) AS discount, coalesce(sum(balance_due), 0) AS due')->first();
        $page = $request->integer('page', 1);
        $rows = $query->orderByDesc('collected_at')->forPage($page, 50)->get();

        return response()->json([
            'totals' => ['count' => (int) $totals->n, 'paid' => (float) $totals->paid, 'discount' => (float) $totals->discount, 'due' => (float) $totals->due],
            'data' => $rows->map(fn (BillingDailyBill $d) => [
                'daily_bill_id' => (int) $d->daily_bill_id, 'customer_header_id' => $d->customer_header_id ? (int) $d->customer_header_id : null,
                'customer_id' => $d->customer_id, 'customer_name' => $d->customer_name, 'username' => $d->username, 'mobile' => $d->mobile,
                'collected_at' => $d->collected_at?->toIso8601String(), 'paid' => (float) $d->receive_amount,
                'discount' => (float) $d->discount_amount, 'due' => (float) $d->balance_due, 'method' => $d->payment_method,
                'received_by' => $d->received_by, 'created_by' => $d->created_by, 'status' => $d->status(),
                'receipt_no' => $d->money_receipt_no,
            ])->values(),
            'next_page' => $rows->count() === 50 ? $page + 1 : null,
        ]);
    }

    /** Bills this member collected from the Desk or the app today (they show here before billing's list syncs them). */
    public function collections(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $since = now('Asia/Dhaka')->startOfDay()->utc();
        $rows = BillCollection::where('company_id', $company->id)->where('user_id', $request->user()->id)
            ->where('result', 'saved')->where('created_at', '>=', $since)->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (BillCollection $c) => [
            'id' => $c->id, 'customer_header_id' => (int) $c->customer_header_id, 'customer_id' => $c->customer_id,
            'customer_name' => $c->customer_name, 'username' => $c->username, 'paid' => (float) $c->paid,
            'discount' => (float) $c->discount, 'method' => $c->payment_method, 'created_at' => $c->created_at?->toIso8601String(),
            'receipt_no' => $c->money_receipt_no ?: 'D-'.$c->id,
        ])->values()]);
    }

    /** The receipt page of a Desk collection (?w=58|80), for the app to draw and send to its Bluetooth printer. */
    public function collectionReceipt(Request $request, Company $company, int $collection): Response
    {
        $this->allow($request, $company);

        return response(Receipts::collection($company, $collection, $request->query('w'), false)->render())
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function dailyBillReceipt(Request $request, Company $company, int $dailyBill): Response
    {
        $this->allow($request, $company);

        return response(Receipts::dailyBill($company, $request->user(), $dailyBill, $request->query('w'), false)->render())
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
