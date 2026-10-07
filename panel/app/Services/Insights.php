<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the live dashboard, always for one company. Timestamps are stored as naive UTC;
 * buckets and "today" are in Bangladesh time.
 */
class Insights
{
    public const TZ = 'Asia/Dhaka';

    private const LOCAL = "((%s AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Dhaka')";

    public function __construct(private int $companyId) {}

    public static function todayStartUtc(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay()->utc();
    }

    /** Messages by sender since $from (UTC). */
    public function messageCounts(CarbonImmutable $from, ?CarbonImmutable $to = null): array
    {
        $rows = DB::table('wa_messages')->where('company_id', $this->companyId)
            ->where('created_at', '>=', $from)->when($to, fn ($q) => $q->where('created_at', '<', $to))
            ->selectRaw('sender, count(*) as n')->groupBy('sender')->pluck('n', 'sender');

        return ['customer' => (int) ($rows['customer'] ?? 0), 'bot' => (int) ($rows['bot'] ?? 0),
            'staff' => (int) (($rows['staff'] ?? 0) + ($rows['app'] ?? 0)), 'campaign' => (int) ($rows['campaign'] ?? 0)];
    }

    public function newContacts(CarbonImmutable $from): int
    {
        return DB::table('wa_contacts')->where('company_id', $this->companyId)->where('created_at', '>=', $from)
            ->whereNotIn('wa_number', DB::table('technicians')->where('company_id', $this->companyId)->select('wa_number'))->count();
    }

    /** Chats whose latest message is from the customer (nobody has answered yet). */
    public function waiting(): int
    {
        return (int) DB::selectOne(
            "SELECT count(*) AS n FROM wa_contacts c WHERE c.company_id = ? AND (
                 SELECT m.direction FROM wa_messages m WHERE m.contact_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) = 'in'
               AND NOT EXISTS (SELECT 1 FROM technicians t WHERE t.company_id = c.company_id AND t.wa_number = c.wa_number)",
            [$this->companyId])->n;
    }

    /** Average seconds between a customer message and the bot's sent reply. */
    public function botResponseSeconds(CarbonImmutable $from): ?int
    {
        $v = DB::selectOne(
            "SELECT avg(extract(epoch FROM d.created_at - m.created_at)) AS s FROM wa_drafts d
               JOIN wa_messages m ON m.id = d.message_id
              WHERE d.company_id = ? AND d.mode = 'sent' AND d.created_at >= ?",
            [$this->companyId, $from])->s;

        return $v === null ? null : (int) round($v);
    }

    /** [opened, requested] tickets since $from. */
    public function tickets(CarbonImmutable $from): array
    {
        $q = DB::table('wa_drafts')->where('company_id', $this->companyId)->where('created_at', '>=', $from)->whereNotNull('ticket_note');

        return [(clone $q)->where('ticket_note', 'like', '%টিকেট খোলা হয়েছে%')->count(), $q->count()];
    }

    /** Category => count, from the bot's ticket notes ("Category | detail → ..."). */
    public function ticketCategories(CarbonImmutable $from): array
    {
        $out = [];
        foreach (DB::table('wa_drafts')->where('company_id', $this->companyId)->where('created_at', '>=', $from)
            ->whereNotNull('ticket_note')->pluck('ticket_note') as $note) {
            $cat = trim(preg_split('/\||→/u', $note)[0]);
            if (str_starts_with($cat, 'কাস্টমার জানতে চায়')) {
                $cat = 'তথ্য জানতে চায়';
            }
            $cat = mb_substr($cat, 0, 30) ?: 'অন্যান্য';
            $out[$cat] = ($out[$cat] ?? 0) + 1;
        }
        arsort($out);

        return array_slice($out, 0, 8, true);
    }

    /**
     * Message counts per hour (last $hours) or per day (last $days), split by sender, with every bucket present.
     * Returns ['labels' => [...], 'customer' => [...], 'bot' => [...], 'staff' => [...]].
     */
    public function series(string $unit, int $count): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $start = $unit === 'hour' ? $now->startOfHour()->subHours($count - 1) : $now->startOfDay()->subDays($count - 1);
        $local = sprintf(self::LOCAL, 'created_at');
        $rows = DB::table('wa_messages')->where('company_id', $this->companyId)->where('created_at', '>=', $start->utc())
            ->selectRaw("to_char(date_trunc('{$unit}', {$local}), 'YYYY-MM-DD HH24') AS b, sender, count(*) AS n")
            ->groupBy('b', 'sender')->get();
        $data = [];
        foreach ($rows as $r) {
            $s = $r->sender === 'app' ? 'staff' : $r->sender;
            $data[$r->b][$s] = ($data[$r->b][$s] ?? 0) + $r->n;
        }
        $out = ['labels' => [], 'customer' => [], 'bot' => [], 'staff' => []];
        for ($i = 0; $i < $count; $i++) {
            $t = $unit === 'hour' ? $start->addHours($i) : $start->addDays($i);
            $key = $t->format('Y-m-d H');
            $out['labels'][] = $unit === 'hour' ? $t->format('g A') : $t->format('d M');
            foreach (['customer', 'bot', 'staff'] as $s) {
                $out[$s][] = (int) ($data[$key][$s] ?? 0);
            }
        }

        return $out;
    }
}
