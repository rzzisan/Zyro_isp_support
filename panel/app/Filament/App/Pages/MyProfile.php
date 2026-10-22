<?php

namespace App\Filament\App\Pages;

use App\Filament\Pages\EditProfile;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * The profile page inside a company (/app/{company}/profile). Filament's own profile route sits outside the
 * tenant, where the company panel's top bar has no company and breaks, so here it is a normal company page.
 * Everyone in the company may open it; it is in the user menu and under Settings.
 */
class MyProfile extends EditProfile
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static bool $isDiscovered = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 0;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return __('আমার পাসওয়ার্ড');
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'profile';
    }

    public static function registerRoutes(Panel $panel, ?\Filament\Pages\PageConfiguration $configuration = null): void
    {
        static::routes($panel, $configuration);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return ($panel ?? \Filament\Facades\Filament::getCurrentOrDefaultPanel())->generateRouteName('profile');
    }

    /** "আমার প্রোফাইল ও পাসওয়ার্ড" at the top of the user menu. */
    public static function menuItem(): Action
    {
        return Action::make('my-profile')->label(fn () => static::getLabel())->icon(Heroicon::OutlinedUserCircle)
            ->url(fn () => static::getUrl())->sort(-3);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
