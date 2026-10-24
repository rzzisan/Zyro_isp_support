<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FieldJob;
use App\Models\FieldLocation;
use App\Models\Membership;
use App\Support\FieldDuty;
use App\Support\FieldWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Android app: a field technician's location. They accept the privacy policy (location recording) at sign-in; then
 * the phone sends points while duty runs (it starts by itself at the office after the start time and ends at the end
 * time or when the day's work is done, FieldDuty), while it waits to reach the office, and while a job runs. Only the
 * owner sees the points (panel "টেকনিশিয়ান লোকেশন"); they are deleted after 90 days.
 */
class FieldLocationController extends Controller
{
    private function member(Request $request, Company $company): Membership
    {
        abort_unless(FieldWork::allowed($request->user(), $company), 403);

        return Membership::where('company_id', $company->id)->where('user_id', $request->user()->id)->firstOrFail();
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $m = $this->member($request, $company);
        FieldDuty::sync($m, $request->user(), $company);

        return response()->json(FieldDuty::state($m, $request->user(), $company));
    }

    /** A batch of points (the app keeps them while offline): [{lat, lng, accuracy, mock, battery, at}]. */
    public function store(Request $request, Company $company): JsonResponse
    {
        $m = $this->member($request, $company);
        abort_unless($m->location_consent_at, 422, __('আগে প্রাইভেসি পলিসি মেনে নিন'));
        $data = $request->validate([
            'points' => ['required', 'array', 'max:500'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'points.*.mock' => ['nullable', 'boolean'],
            'points.*.battery' => ['nullable', 'integer', 'between:0,100'],
            'points.*.at' => ['required', 'date'],
        ]);
        $job = FieldJob::where('company_id', $company->id)->where('status', 'running')
            ->whereIn('ticket_id', FieldWork::tickets($request->user(), $company)->select('billing_tickets.id'))->value('id');
        $rows = [];
        // a point sent twice (a retry after a lost answer, two checks at once) is kept once
        $times = collect($data['points'])->map(fn ($p) => Carbon::parse($p['at'])->utc());
        $seen = FieldLocation::where('company_id', $company->id)->where('user_id', $request->user()->id)
            ->whereBetween('recorded_at', [$times->min(), $times->max()])->pluck('recorded_at')
            ->map(fn ($t) => $t->getTimestamp())->flip();
        foreach ($data['points'] as $p) {
            $at = Carbon::parse($p['at'])->utc();
            if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(3))) {
                continue;   // a wrong phone clock
            }
            if (isset($seen[$at->getTimestamp()])) {
                continue;
            }
            $seen[$at->getTimestamp()] = true;
            $rows[] = ['company_id' => $company->id, 'user_id' => $request->user()->id, 'field_job_id' => $job,
                'lat' => $p['lat'], 'lng' => $p['lng'], 'accuracy' => isset($p['accuracy']) ? (int) round($p['accuracy']) : null,
                'mock' => (bool) ($p['mock'] ?? false), 'battery' => $p['battery'] ?? null, 'recorded_at' => $at, 'created_at' => now()];
        }
        if ($rows) {
            FieldLocation::insert($rows);
        }
        FieldDuty::sync($m, $request->user(), $company, $rows);
        // once a day: points older than 90 days go
        if (Cache::add('field-locations-prune', 1, now()->addDay())) {
            FieldLocation::where('recorded_at', '<', now()->subDays(FieldLocation::KEEP_DAYS))->delete();
        }

        return response()->json(['saved' => count($rows)] + FieldDuty::state($m, $request->user(), $company));
    }
}
