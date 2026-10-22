<?php

namespace App\Support;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\DailyBills\DailyBillResource;
use App\Filament\App\Resources\Tickets\TicketResource;
use App\Models\Company;
use App\Providers\Filament\AppPanelProvider;
use Filament\Facades\Filament;

/**
 * Sidebar "কুইক অ্যাক্সেস" group: the menus the company owner picked (companies.quick_access, class names in order;
 * null = DEFAULT). A picked menu moves out of its own group; members without that menu's permission still don't see it.
 */
class QuickAccess
{
    public const GROUP = 'কুইক অ্যাক্সেস';

    public const DEFAULT = [ConversationResource::class, TicketResource::class, DailyBillResource::class, CustomerResource::class];

    /** Picked menus of the company, in order. */
    public static function classes(?Company $company): array
    {
        return $company ? array_values($company->quick_access ?? self::DEFAULT) : [];
    }

    /** 1-based place of a menu in the current company's quick access, null when it is not there. */
    public static function position(string $class): ?int
    {
        $tenant = Filament::getTenant();
        $i = array_search($class, self::classes($tenant instanceof Company ? $tenant : null), true);

        return $i === false ? null : $i + 1;
    }

    /** Every sidebar menu the current user may put in quick access: class => label, in the normal sidebar order. */
    public static function options(): array
    {
        $panel = Filament::getPanel('app');
        $menus = collect([...$panel->getResources(), ...$panel->getPages()])
            ->unique()
            ->filter(fn (string $c) => in_array(QuickAccessible::class, class_uses_recursive($c), true)
                && $c::getCluster() === null && $c::shouldRegisterNavigation())
            ->sortBy(function (string $c) {
                [$group, $sort] = $c::quickAccessHome();
                $g = array_search($group, AppPanelProvider::NAV_GROUPS, true);

                return sprintf('%03d-%05d', $g === false ? 999 : $g, ($sort ?? 0) + 10000);
            });

        return $menus->mapWithKeys(fn (string $c) => [$c => $c::getNavigationLabel()])->all();
    }

    /** The menu's own group, translated, for the settings page. */
    public static function homeGroupLabel(string $class): string
    {
        $group = $class::quickAccessHome()[0];

        return $group ? __($group) : '';
    }

    /** Keep the order already saved, add newly ticked menus after them in sidebar order. */
    public static function ordered(array $picked, array $before): array
    {
        $picked = array_values(array_intersect(array_keys(self::options()), $picked));
        $kept = array_values(array_intersect($before, $picked));

        return [...$kept, ...array_values(array_diff($picked, $kept))];
    }
}
