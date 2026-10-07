<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\MikrotikRouter;
use App\Models\PppSession;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Online PPPoE customers per MikroTik (refreshed every 2 minutes from the routers). */
class NetworkStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'নেটওয়ার্ক (MikroTik)';

    public static function canView(): bool
    {
        return MikrotikRouter::where('company_id', Filament::getTenant()?->getKey() ?? 0)->exists();
    }

    protected function getStats(): array
    {
        $company = Filament::getTenant()->getKey();
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);
        $total = BillingCustomer::where('company_id', $company)->whereNull('gone_at')->where('status', 'Active')->count();
        // only PPPoE users that exist in the billing software count
        $billed = fn ($q) => $q->whereIn('username', BillingCustomer::where('company_id', $company)->whereNull('gone_at')->select('username'));
        $online = PppSession::where('company_id', $company)->where('seen_at', '>', $fresh)->where($billed)->count();
        $stats = [Stat::make('এখন অনলাইন', number_format($online))
            ->description("Active কাস্টমার {$total} জন")->icon(Heroicon::OutlinedSignal)->color('success')
            ->url(CustomerResource::getUrl('index'))];
        foreach (MikrotikRouter::where('company_id', $company)->orderBy('id')->get() as $r) {
            $n = PppSession::where('router_id', $r->id)->where('seen_at', '>', $fresh)->where($billed)->count();
            $stats[] = Stat::make($r->identity ?: $r->host, number_format($n))
                ->description($r->last_check_ok === false ? 'রাউটারে সংযোগ হচ্ছে না' : 'অনলাইন PPPoE')
                ->descriptionColor($r->last_check_ok === false ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedServerStack);
        }

        return $stats;
    }
}
