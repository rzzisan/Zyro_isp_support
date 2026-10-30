<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\QuickAccessible;
use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
use App\Support\Menu;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * রিমাইন্ডার রিপোর্ট: the voice reminder calls by day (test calls left out): calls, answered %, other numbers, the
 * keys pressed, results, customers who paid within 3 days of being reached, minutes and the rough phone cost
 * (pbx.bd bills each started minute), and the bill reminder SMS the calls saved or sent as a backup.
 */
class VoiceReminderReport extends Page
{
    use QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'voice-reminder-report';

    protected static string|\UnitEnum|null $navigationGroup = 'ভয়েস রিমাইন্ডার';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.app.voice-reminder-report';

    public const TZ = 'Asia/Dhaka';

    /** 7 | 30 | 90 */
    public string $period = '7';

    public static function getNavigationLabel(): string
    {
        return __('রিমাইন্ডার রিপোর্ট');
    }

    public function getTitle(): string|Htmlable
    {
        return __('রিমাইন্ডার কলের রিপোর্ট');
    }

    public static function canAccess(): bool
    {
        return Menu::can('voice_reminder');
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['7', '30', '90'], true) ? $p : '7';
    }

    /** ['days' => [date => row], 'total' => row, 'keys' => [key => n], 'results' => [result => n]] */
    public function report(): array
    {
        $company = (int) Filament::getTenant()->getKey();
        $rate = (float) (VoiceReminderSetting::where('company_id', $company)->value('minute_rate') ?? 0.5);
        $from = Carbon::now(self::TZ)->startOfDay()->subDays((int) $this->period - 1);
        $calls = VoiceReminderCall::where('company_id', $company)->whereIn('kind', ['reminder', 'line_off'])
            ->where('dedupe', 'not like', '%:test')->where('created_at', '>=', $from->copy()->utc())
            ->get(['id', 'kind', 'customer_header_id', 'status', 'result', 'keys', 'seconds', 'answered_at', 'alt_of', 'ref_date',
                'created_at', 'sms_backup', 'reached', 'backup_sms_id']);
        $paid = $this->paidWithin3Days($company, $calls->whereNotNull('answered_at'));
        $blank = ['calls' => 0, 'answered' => 0, 'customers' => 0, 'reached' => 0, 'alt' => 0, 'alt_answered' => 0, 'paid' => 0,
            'minutes' => 0, 'cost' => 0.0, 'sms_saved' => 0, 'sms_backup' => 0];
        $days = [];
        $keys = [];
        $results = [];
        foreach ($calls->groupBy(fn ($c) => ($c->ref_date ?? $c->created_at->timezone(self::TZ))->format('Y-m-d')) as $day => $rows) {
            $placed = $rows->whereIn('status', ['done', 'no_answer', 'busy', 'failed', 'answered']);
            $answered = $rows->whereNotNull('answered_at');
            $reachedHeaders = $answered->pluck('customer_header_id')->unique();
            $minutes = $answered->sum(fn ($c) => (int) ceil(max(1, (int) $c->seconds) / 60));
            $days[$day] = [
                'calls' => $placed->count(),
                'answered' => $answered->count(),
                'customers' => $placed->pluck('customer_header_id')->unique()->count(),
                'reached' => $reachedHeaders->count(),
                'alt' => $placed->whereNotNull('alt_of')->count(),
                'alt_answered' => $answered->whereNotNull('alt_of')->count(),
                'paid' => $reachedHeaders->filter(fn ($h) => isset($paid[$day][$h]))->count(),
                'minutes' => $minutes,
                'cost' => $minutes * $rate,
                'sms_saved' => $rows->whereNull('alt_of')->where('sms_backup', true)->where('reached', true)->count(),
                'sms_backup' => $rows->whereNotNull('backup_sms_id')->count(),
            ];
            foreach ($answered as $c) {
                foreach (preg_split('/\s+/', trim((string) $c->keys), -1, PREG_SPLIT_NO_EMPTY) as $k) {
                    $keys[$k] = ($keys[$k] ?? 0) + 1;
                }
                if ($c->result) {
                    $results[$c->result] = ($results[$c->result] ?? 0) + 1;
                }
            }
        }
        krsort($days);
        ksort($keys);
        arsort($results);
        $total = $blank;
        foreach ($days as $d) {
            foreach ($total as $k => $v) {
                $total[$k] = $v + $d[$k];
            }
        }

        return ['days' => $days, 'total' => $total, 'keys' => $keys, 'results' => $results, 'rate' => $rate];
    }

    /**
     * day => [header_id => true] for answered calls whose customer paid on that day or the 3 days after: billing's
     * last payment date, an online payment, or a bill taken in the Desk.
     */
    private function paidWithin3Days(int $company, Collection $answered): array
    {
        if ($answered->isEmpty()) {
            return [];
        }
        $headers = $answered->pluck('customer_header_id')->unique()->values()->all();
        $byDay = fn ($c) => ($c->ref_date ?? $c->created_at->timezone(self::TZ))->format('Y-m-d');
        $last = DB::table('billing_customers')->where('company_id', $company)->whereIn('header_id', $headers)
            ->whereNotNull('last_payment_date')->pluck('last_payment_date', 'header_id');
        $start = Carbon::parse($answered->map($byDay)->min(), self::TZ)->utc();
        $paidAt = collect();   // header => [Y-m-d, ...] (Bangladesh days)
        foreach (DB::table('billing_online_payments')->where('company_id', $company)->whereIn('customer_header_id', $headers)
            ->where('paid_at', '>=', $start)->get(['customer_header_id', 'paid_at']) as $p) {
            $paidAt->push([(int) $p->customer_header_id, Carbon::parse($p->paid_at, 'UTC')->timezone(self::TZ)->format('Y-m-d')]);
        }
        foreach (DB::table('bill_collections')->where('company_id', $company)->whereIn('customer_header_id', $headers)
            ->where('result', 'saved')->where('created_at', '>=', $start)->get(['customer_header_id', 'created_at']) as $p) {
            $paidAt->push([(int) $p->customer_header_id, Carbon::parse($p->created_at, 'UTC')->timezone(self::TZ)->format('Y-m-d')]);
        }
        foreach ($last as $h => $d) {
            $paidAt->push([(int) $h, substr((string) $d, 0, 10)]);
        }
        $paidAt = $paidAt->groupBy(0)->map(fn ($r) => $r->pluck(1)->all());
        $out = [];
        foreach ($answered as $c) {
            $day = $byDay($c);
            $until = Carbon::parse($day)->addDays(3)->format('Y-m-d');
            foreach ($paidAt[(int) $c->customer_header_id] ?? [] as $d) {
                if ($d >= $day && $d <= $until) {
                    $out[$day][(int) $c->customer_header_id] = true;
                    break;
                }
            }
        }

        return $out;
    }
}
