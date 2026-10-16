<?php

namespace App\Providers\Filament;

use App\Models\Company;
use App\Http\Middleware\AppPanelAuthenticate;
use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\Conversations\Pages\ViewConversation;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Company panel: every page is scoped to the current company (tenant). */
class AppPanelProvider extends PanelProvider
{
    public const NAV_GROUPS = ['সাপোর্ট', 'কাস্টমার ও বিল', 'ONU ও স্টক', 'SMS', 'নেটওয়ার্ক', 'বিলিং কনফিগারেশন', 'WhatsApp ও AI', 'সেটিংস'];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->brandName('Zyro Support')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.5rem')
            ->font('Geist')
            ->maxContentWidth(Width::Full)
            ->sidebarWidth('16rem')
            ->sidebarCollapsibleOnDesktop()
            // sidebar order: daily work first, setup last (items set their group in $navigationGroup, by the Bangla
            // name used as the key here; the label shown is translated)
            ->navigationGroups(collect(self::NAV_GROUPS)->mapWithKeys(fn (string $g) => [$g => NavigationGroup::make()->label(fn () => __($g))])->all())
            ->userMenuItems([Locale::menuItem()])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<link rel="stylesheet" href="'.asset('css/zyro-theme.css').'?v='.filemtime(public_path('css/zyro-theme.css')).'">'.Locale::jsScript()))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => new HtmlString('<script src="'.asset('js/zyro-page-info.js').'?v='.filemtime(public_path('js/zyro-page-info.js')).'"></script>'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.notify-bell'))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('filament.sidebar-accordion'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn () => view('filament.header-shortcuts'))
            // a chat's line status (MikroTik, ONU, due) sits in the page header where the heading would be
            ->renderHook(PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, fn () => ViewConversation::headerLine(), scopes: ViewConversation::class)
            ->tenant(Company::class, slugAttribute: 'slug')
            ->colors(['primary' => Color::hex('#0f7c7b')])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverClusters(in: app_path('Filament/App/Clusters'), for: 'App\Filament\App\Clusters')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([AppPanelAuthenticate::class]);
    }
}
