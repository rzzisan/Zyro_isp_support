<?php

namespace App\Support;

use App\Models\ActivityLog;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Desk's activity log (plan 2.4ক): who did what, from where, before → after. Owner reads it on "কাজের লগ".
 * The engine writes the same table (engine/activity.py). Secrets never go in: password/token/key fields are
 * replaced by "(বদলেছে)". A failed log line never breaks the action it describes.
 */
class Activity
{
    public const CHANGED = '(বদলেছে)';

    private const SECRET = '/pass|pwd|secret|token|api_?key|private|remember|community|crypt/i';

    /** Columns never worth a log line by themselves. */
    private const IGNORE = ['updated_at', 'created_at', 'last_seen_at', 'last_used_at', 'last_message_at', 'synced_at', 'remember_token'];

    /** Models whose writes are bookkeeping or high-volume traffic, not a person's decision (location pings, chat, each campaign message). */
    public const SKIP_MODELS = [ActivityLog::class, \App\Models\FieldLocation::class, \App\Models\StaffChatMessage::class,
        \App\Models\WalkieClip::class, \App\Models\WalkieCall::class, \App\Models\SmsMessage::class, \App\Models\WaCampaignMessage::class,
        \App\Models\PppSession::class, \App\Models\TicketNotification::class, \App\Models\PbxCdr::class, \App\Models\CallLog::class,
        \Laravel\Sanctum\PersonalAccessToken::class];   // last_used_at on every app request; sign-in/out are logged by hand

    public static function log(string $action, array $changes = [], ?Model $subject = null, ?string $note = null,
        ?string $customerCode = null, ?int $companyId = null, ?int $userId = null): void
    {
        try {
            $companyId ??= self::companyId($subject);
            if (! $companyId) {
                return;
            }
            $request = app()->runningInConsole() ? null : request();
            ActivityLog::create([
                'company_id' => $companyId,
                'user_id' => $userId ?? self::userId(),
                'actor' => $userId || self::userId() ? 'user' : 'system',
                'source' => self::source(),
                'action' => Str::limit($action, 100, ''),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'customer_code' => $customerCode ?? self::customerCode($subject),
                'changes' => self::redact($changes) ?: null,
                'note' => $note,
                'ip' => $request?->ip(),
                'device' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('activity log failed: '.$e->getMessage(), ['action' => $action]);
        }
    }

    /** {field: [before, after]} with secrets hidden. */
    public static function redact(array $changes, array $secret = []): array
    {
        $out = [];
        foreach ($changes as $k => $v) {
            $out[$k] = preg_match(self::SECRET, (string) $k) || in_array($k, $secret, true) ? [self::CHANGED, self::CHANGED] : $v;
        }

        return $out;
    }

    /** The fields a save changed, as {field: [before, after]}. */
    public static function diff(Model $model): array
    {
        $out = [];
        foreach ($model->getChanges() as $k => $after) {
            if (in_array($k, self::IGNORE, true)) {
                continue;
            }
            $before = $model->getOriginal($k);
            $out[$k] = [self::plain($before), self::plain($after)];
        }

        return $out;
    }

    /** Eloquent created/updated/deleted of any model, while a person is signed in (panel and app). */
    public static function modelEvent(string $event, Model $model): void
    {
        if (in_array($model::class, self::SKIP_MODELS, true) || ! self::userId()) {
            return;
        }
        $changes = match ($event) {
            'updated' => self::diff($model),
            'created' => collect($model->getAttributes())->except(self::IGNORE)->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => [null, self::plain($v)])->all(),
            default => [],
        };
        if ($event === 'updated' && ! $changes) {
            return;
        }
        // encrypted and hidden attributes are secrets whatever their name
        $secret = [...array_keys(array_filter($model->getCasts(), fn ($c) => is_string($c) && str_contains($c, 'encrypted'))), ...$model->getHidden()];
        $changes = self::redact($changes, $secret);
        self::log(Str::snake(class_basename($model)).'.'.$event, $changes, $model);
    }

    /**
     * The signed-in person, only when the request's guard already knows them. Never asks a guard to resolve a user:
     * that would cache whoever the guard finds now (e.g. a stale token) for the rest of the process.
     */
    public static function userId(): ?int
    {
        $guard = auth()->guard();

        return $guard->hasUser() ? (int) $guard->user()->getAuthIdentifier() : null;
    }

    public static function source(): string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }
        $path = request()->path();

        return str_starts_with($path, 'api/') ? 'app' : 'panel';
    }

    private static function plain(mixed $v): mixed
    {
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return is_string($v) ? Str::limit($v, 500) : $v;
    }

    private static function companyId(?Model $subject): ?int
    {
        if ($subject && isset($subject->company_id)) {
            return (int) $subject->company_id;
        }
        if ($subject instanceof \App\Models\Company) {
            return (int) $subject->getKey();
        }
        $tenant = Filament::getTenant();
        if ($tenant) {
            return (int) $tenant->getKey();
        }
        $user = self::userId() ? auth()->guard()->user() : null;
        $company = $user && method_exists($user, 'companies') ? $user->companies()->first() : null;

        return $company?->getKey();
    }

    private static function customerCode(?Model $subject): ?string
    {
        foreach (['customer_code', 'customer_id'] as $k) {
            $v = $subject?->getAttribute($k);
            if (is_string($v) && $v !== '') {
                return Str::limit($v, 50, '');
            }
        }

        return null;
    }
}
