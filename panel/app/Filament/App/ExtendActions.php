<?php

namespace App\Filament\App;

use App\Filament\App\Pages\BillingExtensions;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskExtension;
use App\Support\Extensions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use RuntimeException;

/** "সময় বাড়ান" on the customer page (billing phase 4a, plan 2.2 #11). */
class ExtendActions
{
    public static function extend(BillingCustomer $b): Action
    {
        $company = fn () => Filament::getTenant();
        $desk = fn () => Extensions::deskCustomer($b);

        return Action::make('extend')->label(__('সময় বাড়ান'))->icon(Heroicon::OutlinedCalendarDays)->color('warning')
            ->visible(fn () => ! $b->gone_at && ! $b->is_left && Extensions::canExtend(auth()->user(), $company()))
            ->modalHeading(fn () => "{$b->name} (ID {$b->customer_id}): ".__('বিল দেওয়ার সময় বাড়ান'))
            ->modalDescription(fn () => static::summary($b))
            ->modalSubmitActionLabel(__('সময় দিন'))
            ->schema(fn () => [
                DatePicker::make('until')->label(__('কোন তারিখ পর্যন্ত লাইন চালু থাকবে'))->required()->native(false)
                    ->displayFormat('d M Y')->minDate(Extensions::today()->addDay())
                    ->maxDate(Extensions::isOwner(auth()->user(), $company()) ? null
                        : Extensions::today()->addDays(max(1, (int) (BillingSetting::forCompany($company())->extend_max_days ?? 10))))
                    ->default(Extensions::today()->addDays(min(7, max(1, (int) (BillingSetting::forCompany($company())->extend_max_days ?? 10))))->toDateString()),
                Textarea::make('reason')->label(__('কারণ'))->required()->rows(2)->maxLength(1000)
                    ->placeholder(__('যেমন: ২০ তারিখে বেতন পেলে দেবেন, আংশিক দিয়েছেন')),
                Toggle::make('enable_line')->label(__('লাইন এখন বন্ধ আছে, চালু করে দিন'))->default(true)
                    ->visible(fn () => ($d = $desk()) && Extensions::lineOff($d)),
            ])
            ->action(function (array $data) use ($company, $desk) {
                $d = $desk();
                if (! $d) {
                    Notification::make()->danger()->title(__('এই কাস্টমার এখনো Desk-এর খাতায় আসেনি'))
                        ->body(__('রাতের কপির পর আবার চেষ্টা করুন।'))->send();

                    return;
                }
                try {
                    $e = Extensions::give($company(), $d, Carbon::parse($data['until'], 'Asia/Dhaka')->startOfDay(), (string) $data['reason'],
                        auth()->user(), 'manual', 'panel', (bool) ($data['enable_line'] ?? false));
                } catch (RuntimeException $ex) {
                    Notification::make()->danger()->title(__('সময় বাড়ানো হয়নি'))->body($ex->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title(__(':d পর্যন্ত সময় দেওয়া হলো', ['d' => $e->until->format('d M Y')]))
                    ->body($e->line_action ? __(DeskExtension::LINE[$e->line_action] ?? $e->line_action) : null)->send();
            });
    }

    /** Current extension, this month's count and the limits, above the form. */
    public static function summary(BillingCustomer $b): HtmlString
    {
        $company = Filament::getTenant();
        $d = Extensions::deskCustomer($b);
        $s = BillingSetting::forCompany($company);
        $lines = [];
        if (! $d) {
            $lines[] = __('এই কাস্টমার এখনো Desk-এর খাতায় আসেনি।');
        } else {
            if ($a = Extensions::active($company->id, $d->id)) {
                $lines[] = __('এখন :d পর্যন্ত সময় দেওয়া আছে (:r)। নতুন তারিখ দিলে আগেরটা বদলে যাবে।', ['d' => $a->until->format('d M Y'), 'r' => $a->reason]);
            }
            if (($due = Extensions::due($d)) !== null) {
                $lines[] = __('বকেয়া :t টাকা।', ['t' => number_format($due)]);
            }
            $lines[] = __('এই মাসে :n বার সময় দেওয়া হয়েছে।', ['n' => Extensions::thisMonth($company->id, $d->id)]);
        }
        $lines[] = __('নিয়ম: একবারে সর্বোচ্চ :d দিন, মাসে :n বার (মালিক এর বাইরেও পারেন)। তারিখ পার হলে বা পুরো বিল দিলে নিজে থেকে উঠে যাবে। বিলিং সফটওয়্যারে কিছু লেখা হয় না।',
            ['d' => $s->extend_max_days ?? 10, 'n' => $s->extend_per_month ?? 1]);

        return new HtmlString(implode('<br>', array_map('e', $lines)).' <a class="underline" href="'.e(BillingExtensions::getUrl()).'">'.e(__('সব সময় বাড়ানো')).'</a>');
    }
}
