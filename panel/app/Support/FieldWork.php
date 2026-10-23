<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\BotSetting;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\FieldJob;
use App\Models\FieldLocation;
use App\Models\Membership;
use App\Models\User;
use App\Models\WaContact;
use App\Services\Engine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Field technicians: a member with the field_jobs menu and a billing employee (team page) sees the tickets that name
 * that employee, and those customers' page and WhatsApp chat. Work on a ticket is a FieldJob; finishing it needs the
 * customer's PPPoE online on MikroTik (checked at most once a minute per customer), unless the category is set not to
 * check or a member with force_solve gives a reason.
 */
class FieldWork
{
    /** A solved ticket stays in the technician's list (and its customer open to them) this long. */
    public const KEEP_HOURS = 24;

    public const LINE_CACHE_SECONDS = 55;

    /** The billing employee name of this member (tickets carry names, not ids), or null when not linked. */
    public static function employeeName(?User $user, Company $company): ?string
    {
        if (! $user) {
            return null;
        }
        $id = Membership::where('company_id', $company->id)->where('user_id', $user->id)->value('billing_employee_id');
        if (blank($id)) {
            return null;
        }
        $name = Engine::ticketOptions($company->id)['employees'][$id] ?? null;

        return filled($name) ? trim($name) : null;
    }

    /** Open tickets naming the member, plus the ones solved in the last day; none when not linked. */
    public static function tickets(?User $user, Company $company): Builder
    {
        $name = static::employeeName($user, $company);

        return BillingTicket::where('company_id', $company->id)
            ->when($name === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($name !== null, fn ($q) => $q->forEmployee($name)->where(fn ($w) => $w->whereIn('state', ['pending', 'processing'])
                ->orWhere('solved_at', '>', now()->subHours(self::KEEP_HOURS))));
    }

    public static function allowed(?User $user, Company $company): bool
    {
        return Menu::allows($user, $company, 'field_jobs');
    }

    public static function mayWork(?User $user, Company $company, BillingTicket $ticket): bool
    {
        return static::allowed($user, $company) && static::tickets($user, $company)->whereKey($ticket->id)->exists();
    }

    /** A technician may open the customer of one of their tickets. */
    public static function mayViewCustomer(?User $user, Company $company, int $headerId): bool
    {
        return $headerId > 0 && static::allowed($user, $company)
            && static::tickets($user, $company)->where('customer_header_id', $headerId)->exists();
    }

    /**
     * A field technician (an agent with the field_jobs menu): even with the inbox menu they only see and write to the
     * WhatsApp chats of customers whose work they have started and not finished.
     */
    public static function restricted(?User $user, Company $company): bool
    {
        return $user !== null && $user->roleIn($company) === 'agent' && static::allowed($user, $company);
    }

    /** The chats of the customers whose job (started, not done) is on one of the member's open tickets. */
    public static function activeContactIds(?User $user, Company $company): array
    {
        if (! static::allowed($user, $company)) {
            return [];
        }
        $headers = static::tickets($user, $company)->whereIn('state', ['pending', 'processing'])->whereNotNull('customer_header_id')
            ->whereHas('fieldJob', fn ($q) => $q->where('status', '!=', 'done'))->pluck('customer_header_id')->unique();

        return BillingCustomer::where('company_id', $company->id)->whereIn('header_id', $headers)->get()
            ->map(fn (BillingCustomer $c) => $c->chat()?->id)->filter()->unique()->values()->all();
    }

    /** ... and that customer's WhatsApp chat, while the work runs. */
    public static function mayChat(?User $user, Company $company, WaContact $contact): bool
    {
        return in_array($contact->id, static::activeContactIds($user, $company), true);
    }

    /** [minutes, line_check] for a ticket category (billing's name), from the field work settings. */
    public static function target(int $companyId, ?string $category): array
    {
        $s = BotSetting::where('company_id', $companyId)->first();
        $minutes = (int) ($s?->field_job_minutes ?: 90);
        $names = Engine::ticketOptions($companyId)['categories'] ?? [];
        foreach ($s?->field_job_targets ?? [] as $row) {
            $name = $names[$row['category_id'] ?? ''] ?? null;
            if ($name !== null && $category !== null && mb_strtolower(trim($name)) === mb_strtolower(trim($category))) {
                return [(int) ($row['minutes'] ?? 0) ?: $minutes, (bool) ($row['line_check'] ?? true)];
            }
        }

        return [$minutes, true];
    }

    /**
     * The customer's PPPoE on MikroTik now: online / blocked / uptime / address, checked_at, error. Cached a minute so many
     * phones on one job ask the router once; $fresh asks now (solving).
     */
    public static function line(int $companyId, int $headerId, bool $fresh = false): array
    {
        $key = "field-line:{$companyId}:{$headerId}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::LINE_CACHE_SECONDS, function () use ($companyId, $headerId) {
            try {
                $r = Engine::monitor($companyId, $headerId, 'recheck');
            } catch (RuntimeException $e) {
                return ['online' => null, 'error' => $e->getMessage(), 'checked_at' => now()->toIso8601String()];
            }

            return ['online' => (bool) ($r['online'] ?? false) && ! ($r['blocked'] ?? false), 'blocked' => (bool) ($r['blocked'] ?? false),
                'uptime' => $r['uptime'] ?? null, 'address' => $r['address'] ?? null, 'router' => $r['router'] ?? null,
                'error' => null, 'checked_at' => now()->toIso8601String()];
        });
    }

    /** Note the line state on the job (for the board) after a check. */
    public static function noteLine(FieldJob $job, array $line): void
    {
        if ($line['online'] !== null) {
            $job->forceFill(['line_online' => $line['online'], 'line_checked_at' => now()])->save();
        }
    }

    /** Jobs whose ticket got solved elsewhere (panel, billing) are done then too. */
    public static function closeSolved(int $companyId): void
    {
        FieldJob::where('company_id', $companyId)->where('status', '!=', 'done')
            ->whereHas('ticket', fn ($q) => $q->whereNotIn('state', ['pending', 'processing']))
            ->with('ticket')->get()
            ->each(function (FieldJob $job) {
                static::finish($job, null, $job->ticket->solved_at);
            });
    }

    public static function finish(FieldJob $job, ?int $userId, $at = null): void
    {
        $at ??= now();
        if ($job->status === 'paused' && $job->paused_at) {
            $job->paused_seconds += max(0, (int) $job->paused_at->diffInSeconds($at));
        }
        $job->forceFill(['status' => 'done', 'paused_at' => null, 'finished_at' => $at, 'finished_by' => $userId])->save();
    }

    /** After any solve that went through (app or panel): the ticket's job is done. */
    public static function solved(BillingTicket $ticket, ?int $userId, ?bool $online = null, ?string $force = null): void
    {
        $job = FieldJob::where('ticket_id', $ticket->id)->where('status', '!=', 'done')->first();
        if (! $job) {
            return;
        }
        if ($online !== null) {
            $job->forceFill(['line_online' => $online, 'line_checked_at' => now()]);
        }
        if ($force) {
            $job->force_reason = $force;
            $job->log('force', $userId, $force);
        }
        $job->log('solve', $userId);
        static::finish($job, $userId);
    }

    /**
     * Whether this ticket may be solved now: null when yes, else why not. A started job of a category that checks the
     * line needs the customer online; a member with force_solve may solve anyway with a reason.
     */
    public static function solveBlock(User $user, Company $company, BillingTicket $ticket, ?string $force, ?FieldJob $job = null): ?string
    {
        $job ??= FieldJob::where('ticket_id', $ticket->id)->where('status', '!=', 'done')->first();
        $check = $job ? $job->line_check : static::target($company->id, $ticket->category)[1];
        if (! $check || ! $ticket->customer_header_id) {
            return null;
        }
        $line = static::line($company->id, (int) $ticket->customer_header_id, true);
        if ($job) {
            static::noteLine($job, $line);
        }
        if ($line['online']) {
            return null;
        }
        if (filled($force)) {
            return Menu::allows($user, $company, 'force_solve') ? null : __('লাইন অফলাইন থাকলে কাজ শেষ করার অনুমতি আপনার নেই');
        }

        return $line['online'] === null
            ? __('রাউটার থেকে লাইনের অবস্থা জানা যায়নি, তাই কাজ শেষ করা যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।')
            : __('কাস্টমারের লাইন এখনো Online হয়নি (MikroTik)। Online হলে তবেই কাজ শেষ করা যাবে।');
    }

    /** The location the app sent with start / solve: lat, lng, accuracy (m), mock; null when none or not valid. */
    public static function point(Request $request): ?array
    {
        $d = $request->validate(['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'], 'mock' => ['nullable', 'boolean']]);
        if (! isset($d['lat'], $d['lng'])) {
            return null;
        }

        return ['lat' => (float) $d['lat'], 'lng' => (float) $d['lng'], 'accuracy' => isset($d['accuracy']) ? (int) round($d['accuracy']) : null,
            'mock' => (bool) ($d['mock'] ?? false)];
    }

    /** Note where the work was started / finished ($at), and how far that is from where the customer is known to be. */
    public static function place(FieldJob $job, string $at, ?array $p): void
    {
        if (! $p) {
            return;
        }
        $headerId = (int) $job->ticket?->customer_header_id;
        $home = $headerId ? CustomerLocation::where('company_id', $job->company_id)->where('header_id', $headerId)->first() : null;
        $job->forceFill(["{$at}_lat" => $p['lat'], "{$at}_lng" => $p['lng'], "{$at}_accuracy" => $p['accuracy'], "{$at}_mock" => $p['mock'],
            "{$at}_distance" => $home ? FieldLocation::distance($home->lat, $home->lng, $p['lat'], $p['lng']) : null])->save();
    }

    /** The first job finished at a customer with the line online, from a real and fairly exact fix, tells where they are. */
    public static function learnCustomer(FieldJob $job, ?array $p): void
    {
        $headerId = (int) $job->ticket?->customer_header_id;
        if (! $p || ! $headerId || $p['mock'] || ($p['accuracy'] ?? 999) > 100 || $job->line_online !== true) {
            return;
        }
        CustomerLocation::firstOrCreate(['company_id' => $job->company_id, 'header_id' => $headerId], ['lat' => $p['lat'], 'lng' => $p['lng']]);
    }
}
