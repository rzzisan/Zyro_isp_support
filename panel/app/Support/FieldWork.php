<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\BotSetting;
use App\Models\Company;
use App\Models\FieldJob;
use App\Models\Membership;
use App\Models\User;
use App\Models\WaContact;
use App\Services\Engine;
use Illuminate\Database\Eloquent\Builder;
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

    /** ... and that customer's WhatsApp chat. */
    public static function mayChat(?User $user, Company $company, WaContact $contact): bool
    {
        if (! static::allowed($user, $company)) {
            return false;
        }
        $headers = static::tickets($user, $company)->whereNotNull('customer_header_id')->pluck('customer_header_id')->unique();

        return BillingCustomer::where('company_id', $company->id)->whereIn('header_id', $headers)->get()
            ->contains(fn (BillingCustomer $c) => $c->chat()?->id === $contact->id);
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
}
