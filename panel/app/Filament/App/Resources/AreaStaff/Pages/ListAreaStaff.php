<?php

namespace App\Filament\App\Resources\AreaStaff\Pages;

use App\Filament\App\Resources\AreaStaff\AreaStaffResource;
use App\Filament\App\TicketActions;
use App\Models\BotSetting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAreaStaff extends ListRecords
{
    protected static string $resource = AreaStaffResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('এলাকার কর্মী');
    }

    public function getSubheading(): ?string
    {
        $on = BotSetting::where('company_id', Filament::getTenant()?->getKey())->value('auto_assign') ?? true;

        return ($on ? __('অটো assign চালু') : __('অটো assign বন্ধ')).' · '
            .__('কোন জোন/সাব-জোন/বক্সে কোন কর্মী টিকিটের কাজ করেছেন। নতুন টিকিটে কর্মী না বাছলে আগে একই বক্স, না পেলে একই সাব-জোন, তারপর একই জোনে সবশেষ কাজ করা কর্মী পান; পিন করা কর্মী আগে।');
    }

    protected function getHeaderActions(): array
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return [
            Action::make('settings')->label(__('অটো assign সেটিং'))->icon(Heroicon::OutlinedCog6Tooth)
                ->visible(fn () => (bool) auth()->user()?->managesCompany(Filament::getTenant()))
                ->fillForm(function () use ($company) {
                    $s = BotSetting::where('company_id', $company())->first();

                    return ['auto_assign' => $s?->auto_assign ?? true, 'auto_assign_exclude' => array_map('strval', $s?->auto_assign_exclude ?? [])];
                })
                ->schema([
                    Toggle::make('auto_assign')->label(__('নতুন টিকিট এলাকার আগের কর্মীকে অটো assign হবে')),
                    Select::make('auto_assign_exclude')->label(__('এই কর্মীরা কখনো অটো assign পাবেন না'))->multiple()->searchable()
                        ->options(fn () => TicketActions::options('employees'))
                        ->helperText(__('যেমন মালিক বা অফিসের কমন লগইন; তাঁরা বাদ গেলে ওই এলাকার পরের কর্মী পাবেন')),
                ])
                ->action(function (array $data) use ($company) {
                    BotSetting::updateOrCreate(['company_id' => $company()], [
                        'auto_assign' => (bool) $data['auto_assign'],
                        'auto_assign_exclude' => array_map('strval', array_values($data['auto_assign_exclude'] ?? [])),
                    ]);
                    Notification::make()->success()->title(__('সেভ হয়েছে'))->send();
                }),
        ];
    }
}
