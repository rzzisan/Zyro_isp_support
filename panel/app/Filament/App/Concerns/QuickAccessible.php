<?php

namespace App\Filament\App\Concerns;

use App\Support\QuickAccess;

/** A sidebar menu the company owner can move into the "কুইক অ্যাক্সেস" group (see App\Support\QuickAccess). */
trait QuickAccessible
{
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return QuickAccess::position(static::class) ? QuickAccess::GROUP : static::$navigationGroup;
    }

    public static function getNavigationSort(): ?int
    {
        return QuickAccess::position(static::class) ?? static::$navigationSort;
    }

    /** Group and sort the menu has outside quick access. */
    public static function quickAccessHome(): array
    {
        return [static::$navigationGroup, static::$navigationSort];
    }
}
