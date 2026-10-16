<?php

namespace App\Filament\App\Widgets;

use App\Models\SmsMessage;
use App\Models\SmsSetting;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

/**
 * SMS page header: the gateway balance (the engine re-reads it every 5 minutes, or on "ব্যালান্স রিফ্রেশ") and the
 * Desk's own counts for today and this month (Bangladesh time).
 */
class SmsStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '15s';

    #[On('sms-balance-updated')]
    public function refreshBalance(): void {}

    protected function getStats(): array
    {
        $cid = Filament::getTenant()->getKey();
        $s = SmsSetting::forCompany($cid);
        $today = Carbon::now('Asia/Dhaka')->startOfDay()->utc();
        $month = Carbon::now('Asia/Dhaka')->startOfMonth()->utc();
        $row = SmsMessage::where('company_id', $cid)->where('created_at', '>=', $month)->toBase()
            ->selectRaw("count(*) FILTER (WHERE status IN ('sent','delivered') AND sent_at >= ?) AS today,
                coalesce(sum(parts) FILTER (WHERE status IN ('sent','delivered')), 0) AS month,
                count(*) FILTER (WHERE status = 'failed') AS failed,
                count(*) FILTER (WHERE status IN ('queued','sending')) AS waiting", [$today])->first();
        $state = ! $s->configured() ? __('গেটওয়ে সেট করা হয়নি') : ($s->enabled ? __('পাঠানো চালু') : __('পাঠানো বন্ধ'));
        $balance = match (true) {
            $s->balance !== null && $s->last_check_ok !== false => number_format((float) $s->balance, 2),
            $s->balance_checked_at !== null => __('পাওয়া যায়নি'),
            default => '—',
        };
        $checked = $s->balance_checked_at
            ? ($s->last_check_ok === false ? mb_substr((string) $s->last_error, 0, 80).' · ' : __('দেখা হয়েছে '))
                .$s->balance_checked_at->diffForHumans().' · '
            : '';

        return [
            Stat::make(__('গেটওয়ে ব্যালান্স'), $balance)->icon(Heroicon::OutlinedWallet)
                ->color($s->configured() && $s->enabled ? 'success' : 'warning')->description($checked.$state),
            Stat::make(__('আজ গেছে'), number_format((int) $row->today))->icon(Heroicon::OutlinedPaperAirplane),
            Stat::make(__('এই মাসে (SMS ভাগ)'), number_format((int) $row->month))->icon(Heroicon::OutlinedCalendar)
                ->description($s->rate !== null ? __('আনুমানিক :amount টাকা', ['amount' => number_format((int) $row->month * (float) $s->rate, 2)]) : null),
            Stat::make(__('এই মাসে ব্যর্থ'), number_format((int) $row->failed))->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')
                ->description($row->waiting ? __(':nটি এখনো অপেক্ষায়', ['n' => number_format((int) $row->waiting)]) : null),
        ];
    }
}
