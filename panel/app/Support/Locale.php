<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;

/**
 * Panel language. Strings in the code are the Bangla text wrapped in __(); lang/en.json holds their English.
 * Order: the user's own choice, then their (first) company's default, then Bangla.
 */
class Locale
{
    public const SUPPORTED = ['bn' => 'বাংলা', 'en' => 'English'];

    public const DEFAULT = 'bn';

    public static function normalize(?string $locale): ?string
    {
        return isset(self::SUPPORTED[$locale]) ? $locale : null;
    }

    public static function for(?User $user): string
    {
        if (! $user) {
            return self::DEFAULT;
        }

        return self::normalize($user->locale)
            ?? self::normalize($user->companies()->orderBy('companies.id')->value('default_locale'))
            ?? self::DEFAULT;
    }

    public static function apply(string $locale): void
    {
        app()->setLocale($locale);
        Carbon::setLocale($locale);
    }

    /** The other language, for the switch in the user menu. */
    public static function other(): string
    {
        return app()->getLocale() === 'en' ? 'bn' : 'en';
    }

    /**
     * Every Bangla text the panel translates: the literal __('...') calls in app/ and resources/views, plus labels kept
     * in constants and passed through __() where shown. lang/en.json must have all of them (tests/Feature/LocaleTest).
     */
    public static function keys(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        $files = [...iterator_to_array($files), ...iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))))];
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", file_get_contents($file->getPathname()), $m);
            array_push($keys, ...array_map('stripslashes', $m[1]));
        }
        array_push($keys, ...array_values(\App\Models\BillingTicket::STATES),
            ...array_values(\App\Filament\App\Resources\Conversations\Pages\ListConversations::BOXES),
            ...array_column(Menu::ITEMS, 0), ...\App\Providers\Filament\AppPanelProvider::NAV_GROUPS,
            ...array_values(\App\Filament\App\Resources\Customers\History\ChangesTable::LABELS),
            ...array_values(\App\Filament\App\Resources\DailyBills\DailyBillResource::STATUSES),
            ...array_values(\App\Models\SmsCampaign::STATUSES), ...array_values(\App\Models\SmsMessage::STATUSES),
            ...array_values(\App\Models\SmsTemplate::TYPES), ...array_values(\App\Filament\App\Pages\SmsSettings::EVENTS),
            ...array_column(Sms::PLACEHOLDERS, 0), ...array_values(Sms::BILL_PLACEHOLDERS),
            ...array_values(Sms::TICKET_PLACEHOLDERS), ...array_values(Sms::REMINDER_PLACEHOLDERS),
            ...array_values(\App\Filament\App\Pages\AiUsage::PURPOSES));
        $keys = array_values(array_unique(array_filter($keys, fn ($k) => preg_match('/[\x{0980}-\x{09FF}]/u', $k))));
        sort($keys);

        return $keys;
    }

    /** User-menu item of both panels: shows the other language ("English" / "বাংলা") and switches to it. */
    public static function menuItem(): Action
    {
        return Action::make('locale')
            ->label(fn () => self::SUPPORTED[self::other()])
            ->icon('heroicon-o-language')
            ->url(fn () => route('locale.switch', self::other()));
    }
}
