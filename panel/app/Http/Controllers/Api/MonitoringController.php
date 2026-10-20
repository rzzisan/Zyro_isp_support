<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\MikrotikRouter;
use App\Models\PppSession;
use App\Services\Engine;
use App\Support\Menu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Android app: online client monitoring, like the panel's page. Every current customer whose line is on, with online /
 * offline / blocked from our MikroTik copy, IP, MAC, uptime and when last seen; per-server counts; re-check, traffic, ping.
 */
class MonitoringController extends Controller
{
    private function allow(Request $request, Company $company): void
    {
        abort_unless(Menu::allows($request->user(), $company, 'monitoring'), 403);
        abort_unless(MikrotikRouter::where('company_id', $company->id)->exists(), 404, __('কোনো MikroTik রাউটার যোগ করা নেই'));
    }

    /** Current customers with the line on, joined to their PPPoE session as s. */
    private function base(Company $company): Builder
    {
        return BillingCustomer::query()->where('billing_customers.company_id', $company->id)
            ->whereNull('gone_at')->where('is_left', false)->where('billing_customers.disabled', false)
            ->leftJoin('ppp_sessions as s', fn ($j) => $j->on('s.company_id', '=', 'billing_customers.company_id')
                ->on('s.username', '=', 'billing_customers.username'));
    }

    /** ?state=online|offline|blocked, ?q= (ID, name, PPPoE or mobile), ?zone= &subzone= &box= (not ?server: the cards count per server). */
    private function filter(Builder $q, Request $request): Builder
    {
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);
        match ($request->query('state')) {
            'online' => $q->where('s.seen_at', '>', $fresh)->where('s.blocked', false),
            'blocked' => $q->where('s.seen_at', '>', $fresh)->where('s.blocked', true),
            'offline' => $q->where(fn ($w) => $w->whereNull('s.seen_at')->orWhere('s.seen_at', '<=', $fresh)),
            default => null,
        };
        foreach (['zone', 'subzone', 'box'] as $col) {
            if (filled($v = $request->query($col))) {
                $q->where("billing_customers.{$col}", $v);
            }
        }
        if (($search = trim((string) $request->query('q'))) !== '') {
            $like = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $digits = preg_replace('/\D/', '', $search);
            $q->where(fn ($w) => $w
                ->whereRaw("ltrim(billing_customers.customer_id, '0') = ltrim(?, '0')", [$search])
                ->orWhere('billing_customers.name', $like, "%{$search}%")
                ->orWhere('billing_customers.username', $like, "%{$search}%")
                ->when($digits !== '', fn ($x) => $x->orWhere('billing_customers.mobile', 'like', "%{$digits}%")));
        }

        return $q;
    }

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);
        $page = max(1, $request->integer('page', 1));
        $server = (string) $request->query('server', '');

        $rows = $this->filter($this->base($company), $request)
            ->when($server !== '', fn ($q) => $q->where('billing_customers.server', $server))
            ->select('billing_customers.*', 's.address as s_address', 's.caller_id as s_mac', 's.uptime as s_uptime', 's.seen_at as s_seen',
                's.profile as s_profile', 's.blocked as s_blocked_flag')
            ->orderBy('billing_customers.customer_id')->forPage($page, 50)->get();

        $data = $rows->map(function (BillingCustomer $c) use ($fresh) {
            $seen = $c->s_seen ? \Illuminate\Support\Carbon::parse($c->s_seen, 'UTC') : null;
            $live = $seen && $seen->gt($fresh);
            $blocked = $live && (bool) $c->s_blocked_flag;
            $online = $live && ! $blocked;

            return CustomerController::row($c, $online) + [
                'state' => $online ? 'online' : ($blocked ? 'blocked' : 'offline'),
                'server' => $c->server,
                'ip' => $live ? $c->s_address : null, 'mac' => $live ? $c->s_mac : null, 'uptime' => $live ? $c->s_uptime : null,
                'profile' => $blocked ? $c->s_profile : null,
                'seen_at' => $seen?->toIso8601String(),
            ];
        });

        $counts = null;
        if ($page === 1) {
            $grouped = $this->filter($this->base($company), $request)
                ->selectRaw('billing_customers.server AS server, count(*) AS total,
                    sum(CASE WHEN s.seen_at > ? AND NOT s.blocked THEN 1 ELSE 0 END) AS online,
                    sum(CASE WHEN s.seen_at > ? AND s.blocked THEN 1 ELSE 0 END) AS blocked', [$fresh, $fresh])
                ->groupBy('billing_customers.server')->orderByDesc('total')->get();
            $counts = [['server' => '', 'total' => (int) $grouped->sum('total'), 'online' => (int) $grouped->sum('online'),
                'blocked' => (int) $grouped->sum('blocked')]];
            foreach ($grouped as $g) {
                if ($g->server && $g->server !== 'Not Found') {
                    $counts[] = ['server' => $g->server, 'total' => (int) $g->total, 'online' => (int) $g->online, 'blocked' => (int) $g->blocked];
                }
            }
        }

        return response()->json([
            'data' => $data->values(), 'next_page' => $rows->count() === 50 ? $page + 1 : null, 'servers' => $counts,
            'updated_at' => ($last = PppSession::where('company_id', $company->id)->max('seen_at'))
                ? \Illuminate\Support\Carbon::parse($last, 'UTC')->toIso8601String() : null,
        ]);
    }

    /** Ask the customer's router now: what = recheck | traffic | ping (the panel's row buttons). */
    public function check(Request $request, Company $company, int $headerId, string $what): JsonResponse
    {
        $this->allow($request, $company);
        abort_unless(in_array($what, ['recheck', 'traffic', 'ping'], true), 404);
        BillingCustomer::where('company_id', $company->id)->where('header_id', $headerId)->firstOrFail();
        try {
            return response()->json(['result' => Engine::monitor($company->id, $headerId, $what)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** Read every router's online list now (the panel's "এখনই Sync"). */
    public function sync(Request $request, Company $company): JsonResponse
    {
        $this->allow($request, $company);
        try {
            $rows = Engine::syncOnline($company->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['routers' => array_is_list($rows) ? $rows : []]);
    }
}
