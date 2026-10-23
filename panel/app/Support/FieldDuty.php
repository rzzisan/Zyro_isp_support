<?php

namespace App\Support;

use App\Models\BotSetting;
use App\Models\Company;
use App\Models\FieldJob;
use App\Models\FieldLocation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A field technician's duty, without a switch: after the start time (10:00) it starts once their phone is within the
 * office radius (20 m), and ends at the end time (17:00) or once the day's work is done (tickets put off to tomorrow
 * don't count). The app sends location only while duty runs, while it waits for the office, or while a job runs; the
 * technician accepted this in the privacy policy at sign-in.
 */
class FieldDuty
{
    public const TZ = 'Asia/Dhaka';

    /** [lat, lng, radius m, start "HH:MM", end "HH:MM"] */
    public static function settings(int $companyId): array
    {
        $s = BotSetting::where('company_id', $companyId)->first();

        return [$s?->field_office_lat !== null ? (float) $s->field_office_lat : null, $s?->field_office_lng !== null ? (float) $s->field_office_lng : null,
            (int) ($s?->field_office_radius ?: 20), $s?->field_duty_start ?: '10:00', $s?->field_duty_end ?: '17:00'];
    }

    public static function today(): string
    {
        return now(self::TZ)->toDateString();
    }

    /** [start, end] of today's duty hours, in UTC. */
    public static function hours(int $companyId): array
    {
        [, , , $start, $end] = static::settings($companyId);
        $day = now(self::TZ)->startOfDay();

        return [static::at($day, $start)->utc(), static::at($day, $end)->utc()];
    }

    private static function at(Carbon $day, string $hm): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $hm.':0'));

        return $day->copy()->setTime($h, $m);
    }

    public static function inHours(int $companyId): bool
    {
        [$from, $to] = static::hours($companyId);

        return now()->between($from, $to);
    }

    /** Open tickets still to do today (not put off to a later day). */
    public static function remaining(User $user, Company $company): int
    {
        return FieldWork::tickets($user, $company)->whereIn('state', ['pending', 'processing'])
            ->where(fn ($q) => $q->whereNull('field_later_on')->orWhere('field_later_on', '<=', static::today()))->count();
    }

    public static function running(User $user, Company $company): bool
    {
        return FieldWork::tickets($user, $company)->whereIn('state', ['pending', 'processing'])
            ->whereHas('fieldJob', fn ($q) => $q->where('status', 'running'))->exists();
    }

    private static function finishedToday(User $user, Company $company): bool
    {
        return FieldJob::where('company_id', $company->id)->where('finished_by', $user->id)
            ->where('finished_at', '>=', now(self::TZ)->startOfDay()->utc())->exists();
    }

    /** Whether a point puts the phone at the office (its accuracy, up to 30 m, gives it the benefit of the doubt). */
    public static function atOffice(int $companyId, array $p): bool
    {
        [$lat, $lng, $radius] = static::settings($companyId);
        if ($lat === null || $lng === null) {
            return true;   // no office set: duty starts with the first point in duty hours
        }
        if (! empty($p['mock'])) {
            return false;
        }

        return FieldLocation::distance($lat, $lng, (float) $p['lat'], (float) $p['lng']) - min((int) ($p['accuracy'] ?? 0), 30) <= $radius;
    }

    /** Start or end the member's duty for the time, the work left and the points just sent. */
    public static function sync(Membership $m, User $user, Company $company, array $points = []): void
    {
        $in = static::inHours($company->id);
        $today = static::today();
        if ($m->on_duty_since) {
            $over = ! $in || $m->on_duty_since->timezone(self::TZ)->toDateString() !== $today
                || (static::remaining($user, $company) === 0 && ! static::running($user, $company) && static::finishedToday($user, $company));
            if ($over) {
                $m->forceFill(['on_duty_since' => null, 'duty_ended_at' => now()])->save();
            }

            return;
        }
        if (! $in || ! $m->location_consent_at) {
            return;
        }
        if ($m->duty_day?->toDateString() === $today) {
            // reached the office today already and the work was done; new work brings duty back
            if (static::remaining($user, $company) > 0) {
                $m->forceFill(['on_duty_since' => now(), 'duty_ended_at' => null])->save();
            }

            return;
        }
        [$from] = static::hours($company->id);
        foreach ($points as $p) {
            if (Carbon::parse($p['recorded_at'] ?? $p['at'])->gte($from) && static::atOffice($company->id, $p)) {
                $m->forceFill(['on_duty_since' => now(), 'duty_day' => $today, 'duty_ended_at' => null])->save();

                return;
            }
        }
    }

    /** What the app needs: whether to send location now. */
    public static function state(Membership $m, User $user, Company $company): array
    {
        $running = static::running($user, $company);
        $in = static::inHours($company->id);
        $waiting = $m->duty_day?->toDateString() !== static::today();   // not at the office yet today
        $consent = $m->location_consent_at !== null;

        return ['consent' => $consent, 'on_duty' => $m->on_duty_since !== null, 'on_duty_since' => $m->on_duty_since?->toIso8601String(),
            'job_running' => $running, 'track' => $consent && (($in && ($m->on_duty_since !== null || $waiting)) || $running),
            'interval' => $running || ($in && $waiting) ? 60 : 300];
    }
}
