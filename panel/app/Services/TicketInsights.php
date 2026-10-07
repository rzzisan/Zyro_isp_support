<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Billing-ticket numbers for the dashboard (one company; buckets in Bangladesh time). */
class TicketInsights
{
    private const LOCAL = "((%s AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Dhaka')";

    public function __construct(private int $companyId) {}

    private function q()
    {
        return DB::table('billing_tickets')->where('company_id', $this->companyId);
    }

    public function openCount(?string $state = null): int
    {
        return $this->q()->whereIn('state', $state ? [$state] : ['pending', 'processing'])->count();
    }

    public function openedSince(CarbonImmutable $from): int
    {
        return $this->q()->where('opened_at', '>=', $from)->count();
    }

    public function solvedSince(CarbonImmutable $from): int
    {
        return $this->q()->where('state', 'solved')->where('solved_at', '>=', $from)->count();
    }

    /** Average minutes from opening to solving, for tickets solved since $from. */
    public function avgSolveMinutes(CarbonImmutable $from): ?int
    {
        $v = $this->q()->where('state', 'solved')->where('solved_at', '>=', $from)->whereNotNull('opened_at')
            ->selectRaw('avg(extract(epoch FROM solved_at - opened_at)) / 60 AS m')->value('m');

        return $v === null ? null : (int) round($v);
    }

    /** Open tickets older than $hours. */
    public function overdue(int $hours = 24): int
    {
        return $this->q()->whereIn('state', ['pending', 'processing'])->where('opened_at', '<', now()->subHours($hours))->count();
    }

    /** Opened vs solved per day for the last $days days. */
    public function daily(int $days): array
    {
        $start = CarbonImmutable::now(Insights::TZ)->startOfDay()->subDays($days - 1);
        $count = function (string $col) use ($start) {
            $local = sprintf(self::LOCAL, $col);

            return $this->q()->where($col, '>=', $start->utc())
                ->when($col === 'solved_at', fn ($q) => $q->where('state', 'solved'))
                ->selectRaw("to_char({$local}, 'YYYY-MM-DD') AS d, count(*) AS n")->groupBy('d')->pluck('n', 'd');
        };
        $opened = $count('opened_at');
        $solved = $count('solved_at');
        $out = ['labels' => [], 'opened' => [], 'solved' => []];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->addDays($i);
            $out['labels'][] = $d->format('d M');
            $out['opened'][] = (int) ($opened[$d->format('Y-m-d')] ?? 0);
            $out['solved'][] = (int) ($solved[$d->format('Y-m-d')] ?? 0);
        }

        return $out;
    }

    /** Top values of a column (zone, category, solved_by) for tickets opened since $from, optionally only open ones. */
    public function top(string $column, CarbonImmutable $from, bool $openOnly = false, int $limit = 8): array
    {
        abort_unless(in_array($column, ['zone', 'category', 'solved_by'], true), 500);

        return $this->q()->whereNotNull($column)->where($column, '!=', '')
            ->when($openOnly, fn ($q) => $q->whereIn('state', ['pending', 'processing']),
                fn ($q) => $q->where($column === 'solved_by' ? 'solved_at' : 'opened_at', '>=', $from))
            ->selectRaw("{$column} AS k, count(*) AS n")->groupBy('k')->orderByDesc('n')->limit($limit)
            ->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }
}
