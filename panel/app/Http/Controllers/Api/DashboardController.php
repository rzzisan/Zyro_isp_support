<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\BillingDailyBill;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\PppSession;
use App\Services\Insights;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Android app home: today at a glance (Bangladesh day), only the parts the member's menus allow. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        $today = now('Asia/Dhaka')->startOfDay()->utc();
        $out = [];
        if (Menu::allows($user, $company, 'bills')) {
            $bills = BillingDailyBill::where('company_id', $company->id)->visibleTo($request->attributes->get('membership'))
                ->where('is_deleted', false)->where('collected_at', '>=', $today)->toBase()
                ->selectRaw('count(*) AS n, coalesce(sum(receive_amount), 0) AS paid, coalesce(sum(CASE WHEN is_approved THEN 0 ELSE receive_amount END), 0) AS pending')->first();
            $mine = BillCollection::where('company_id', $company->id)->where('user_id', $user->id)->where('result', 'saved')
                ->where('created_at', '>=', $today)->toBase()->selectRaw('count(*) AS n, coalesce(sum(paid), 0) AS paid')->first();
            $out['bills'] = ['count' => (int) $bills->n, 'paid' => (float) $bills->paid, 'pending' => (float) $bills->pending,
                'mine_count' => (int) $mine->n, 'mine_paid' => (float) $mine->paid];
        }
        if (Menu::allows($user, $company, 'tickets')) {
            $out['tickets'] = [
                'open' => BillingTicket::where('company_id', $company->id)->whereIn('state', ['pending', 'processing'])->count(),
                'opened_today' => BillingTicket::where('company_id', $company->id)->where('opened_at', '>=', $today)->count(),
                'solved_today' => BillingTicket::where('company_id', $company->id)->where('solved_at', '>=', $today)->count(),
            ];
        }
        if (Menu::allows($user, $company, 'monitoring') || Menu::allows($user, $company, 'customers')) {
            $online = PppSession::where('company_id', $company->id)->where('seen_at', '>', now()->subMinutes(PppSession::FRESH_MINUTES))
                ->where('blocked', false)->count();
            $active = BillingCustomer::where('company_id', $company->id)->whereNull('gone_at')->where('is_left', false)
                ->where('disabled', false)->count();
            $out['network'] = ['online' => $online, 'active_customers' => $active];
        }
        if (Menu::allows($user, $company, 'inbox')) {
            $out['inbox'] = ['waiting' => (new Insights($company->id))->waiting()];
        }

        return response()->json($out);
    }
}
