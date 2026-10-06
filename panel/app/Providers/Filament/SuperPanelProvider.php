<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Platform owner (Zyrotech): companies, plans, all users. */
class SuperPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('super')
            ->path('super')
            ->login()
            ->brandName('Zyro Support · Super Admin')
            ->brandLogo(fn () => view('filament.brand', ['sub' => 'প্ল্যাটফর্ম অ্যাডমিন']))
            ->brandLogoHeight('2.5rem')
            ->font('Geist')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<link rel="stylesheet" href="'.asset('css/zyro-theme.css').'?v='.filemtime(public_path('css/zyro-theme.css')).'">'))
            ->colors(['primary' => Color::hex('#0f7c7b')])
            ->discoverResources(in: app_path('Filament/Super/Resources'), for: 'App\Filament\Super\Resources')
            ->discoverPages(in: app_path('Filament/Super/Pages'), for: 'App\Filament\Super\Pages')
            ->pages([Dashboard::class])
            ->widgets([AccountWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
