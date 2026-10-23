<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FieldJob;
use App\Models\FieldLocation;
use App\Models\Membership;
use App\Support\FieldWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Android app: a field technician's location, told to them. They agree once (consent screen), then the app sends points
 * while they are on duty ("ডিউটি শুরু") or a job of theirs runs, with a notification showing the whole time. Only the
 * owner sees the points (panel "টেকনিশিয়ান লোকেশন"); they are deleted after 90 days.
 */
class FieldLocationController extends Controller
{
    private function member(Request $request, Company $company): Membership
    {
        abort_unless(FieldWork::allowed($request->user(), $company), 403);

        return Membership::where('company_id', $company->id)->where('user_id', $request->user()->id)->firstOrFail();
    }

    /** Whether to track now: consent given, and on duty or a job running. */
    private function state(Request $request, Company $company, Membership $m): array
    {
        $running = FieldWork::tickets($request->user(), $company)->whereIn('state', ['pending', 'processing'])
            ->whereHas('fieldJob', fn ($q) => $q->where('status', 'running'))->exists();

        return ['consent' => $m->location_consent_at !== null, 'on_duty' => $m->on_duty_since !== null,
            'on_duty_since' => $m->on_duty_since?->toIso8601String(), 'job_running' => $running,
            'track' => $m->location_consent_at !== null && ($m->on_duty_since !== null || $running),
            'interval' => $running ? 60 : 300];
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $m = $this->member($request, $company);

        return response()->json($this->state($request, $company, $m));
    }

    /** The technician read the notice and agreed. */
    public function consent(Request $request, Company $company): JsonResponse
    {
        $m = $this->member($request, $company);
        $m->forceFill(['location_consent_at' => $m->location_consent_at ?? now()])->save();

        return response()->json($this->state($request, $company, $m));
    }

    /** on = true starts duty (tracking every 5 minutes), false ends it. */
    public function duty(Request $request, Company $company): JsonResponse
    {
        $m = $this->member($request, $company);
        $on = $request->validate(['on' => ['required', 'boolean']])['on'];
        abort_if($on && ! $m->location_consent_at, 422, __('আগে লোকেশনের নোটিশে সম্মতি দিন'));
        $m->forceFill(['on_duty_since' => $on ? ($m->on_duty_since ?? now()) : null])->save();

        return response()->json($this->state($request, $company, $m));
    }

    /** A batch of points (the app keeps them while offline): [{lat, lng, accuracy, mock, battery, at}]. */
    public function store(Request $request, Company $company): JsonResponse
    {
        $m = $this->member($request, $company);
        abort_unless($m->location_consent_at, 422, __('আগে লোকেশনের নোটিশে সম্মতি দিন'));
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
        foreach ($data['points'] as $p) {
            $at = Carbon::parse($p['at'])->utc();
            if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(3))) {
                continue;   // a wrong phone clock
            }
            $rows[] = ['company_id' => $company->id, 'user_id' => $request->user()->id, 'field_job_id' => $job,
                'lat' => $p['lat'], 'lng' => $p['lng'], 'accuracy' => isset($p['accuracy']) ? (int) round($p['accuracy']) : null,
                'mock' => (bool) ($p['mock'] ?? false), 'battery' => $p['battery'] ?? null, 'recorded_at' => $at, 'created_at' => now()];
        }
        if ($rows) {
            FieldLocation::insert($rows);
        }
        // once a day: points older than 90 days go
        if (Cache::add('field-locations-prune', 1, now()->addDay())) {
            FieldLocation::where('recorded_at', '<', now()->subDays(FieldLocation::KEEP_DAYS))->delete();
        }

        return response()->json(['saved' => count($rows)] + $this->state($request, $company, $m));
    }
}
