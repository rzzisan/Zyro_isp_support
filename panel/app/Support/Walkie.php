<?php

namespace App\Support;

use App\Models\BotSetting;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\WalkieGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Walkie-talkie for the Desk app, on our own LiveKit server (services.livekit). The app joins a group's room with a
 * token from here: everyone listens, push-to-talk unmutes the microphone. A private call is a two-person room. The
 * engine (engine/walkie.py) records every transmission and call, and pushes the app on and off at the company's times.
 * Members are whoever the owner put in a group; the owner is in only the groups they put themselves in.
 */
class Walkie
{
    public static function configured(): bool
    {
        return filled(config('services.livekit.url')) && filled(config('services.livekit.key')) && filled(config('services.livekit.secret'));
    }

    /** The groups this member is in (none when LiveKit is not set up). */
    public static function groupsFor(User $user, Company $company): Collection
    {
        if (! static::configured()) {
            return collect();
        }
        $m = Membership::where('company_id', $company->id)->where('user_id', $user->id)->first();

        return $m ? $m->walkieGroups()->orderBy('walkie_groups.name')->get() : collect();
    }

    public static function memberOf(User $user, WalkieGroup $group): bool
    {
        return $group->memberships()->where('company_user.user_id', $user->id)->exists();
    }

    /** May these two call each other: both in at least one group together. */
    public static function share(Company $company, int $a, int $b): bool
    {
        return WalkieGroup::where('company_id', $company->id)
            ->whereHas('memberships', fn ($q) => $q->where('company_user.user_id', $a))
            ->whereHas('memberships', fn ($q) => $q->where('company_user.user_id', $b))->exists();
    }

    /** ["10:00", "22:00"|null]: when the app turns itself on and off each day (Asia/Dhaka). */
    public static function hours(int $companyId): array
    {
        $s = BotSetting::where('company_id', $companyId)->first();

        return [$s?->walkie_on_at ?: '10:00', $s?->walkie_off_at ?: null];
    }

    public static function identity(int $userId): string
    {
        return "u{$userId}";
    }

    /** A LiveKit access token (HS256 JWT) to join [room] as this user. */
    public static function token(User $user, string $room, int $ttl = 43200): string
    {
        $now = time();

        return static::jwt([
            'iss' => config('services.livekit.key'), 'sub' => static::identity($user->id), 'name' => $user->name,
            'nbf' => $now - 10, 'exp' => $now + $ttl,
            'video' => ['room' => $room, 'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => true,
                'canPublishData' => true, 'canPublishSources' => ['microphone']],
        ]);
    }

    /** The user ids connected to a room right now (LiveKit's room API; [] when it can't be asked). */
    public static function online(string $room): array
    {
        try {
            $now = time();
            $admin = static::jwt(['iss' => config('services.livekit.key'), 'nbf' => $now - 10, 'exp' => $now + 60,
                'video' => ['room' => $room, 'roomAdmin' => true]]);
            $rows = Http::timeout(3)->withToken($admin)
                ->post(rtrim((string) config('services.livekit.api_url'), '/').'/twirp/livekit.RoomService/ListParticipants', ['room' => $room])
                ->json('participants') ?? [];
        } catch (\Throwable) {
            return [];
        }

        return collect($rows)->pluck('identity')->filter(fn ($i) => is_string($i) && preg_match('/^u\d+$/', $i))
            ->map(fn ($i) => (int) substr($i, 1))->values()->all();
    }

    private static function jwt(array $claims): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($claims, JSON_UNESCAPED_UNICODE));

        return "{$head}.{$body}.".$b64(hash_hmac('sha256', "{$head}.{$body}", (string) config('services.livekit.secret'), true));
    }
}
