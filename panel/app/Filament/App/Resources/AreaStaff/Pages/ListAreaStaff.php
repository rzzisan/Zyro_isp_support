<?php

namespace App\Filament\App\Resources\AreaStaff\Pages;

use App\Filament\App\Resources\AreaStaff\AreaStaffResource;
use App\Filament\App\TicketActions;
use App\Models\BotSetting;
use App\Models\StaffTeam;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
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
            .__('কোন জোন/সাব-জোন/বক্সে কোন কর্মী টিকিটের কাজ করেছেন। সেটিংয়ে বাছাই করা ক্যাটাগরির নতুন টিকিটে কর্মী না বাছলে আগে একই বক্স, না পেলে একই সাব-জোন, তারপর একই জোনে সবশেষ কাজ করা কর্মী পান; পিন করা কর্মী আগে।');
    }

    protected function getHeaderActions(): array
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        return [
            Action::make('settings')->label(__('অটো assign সেটিং'))->icon(Heroicon::OutlinedCog6Tooth)
                ->visible(fn () => (bool) auth()->user()?->managesCompany(Filament::getTenant()))
                ->fillForm(function () use ($company) {
                    $s = BotSetting::where('company_id', $company())->first();

                    return ['auto_assign' => $s?->auto_assign ?? true, 'auto_assign_exclude' => array_map('strval', $s?->auto_assign_exclude ?? []),
                        'auto_assign_area_categories' => array_map('strval', $s?->auto_assign_area_categories ?? []),
                        'auto_assign_category_staff' => array_map(fn ($r) => ['category_id' => (string) $r['category_id'],
                            'employees' => array_map('strval', $r['employees'] ?? [])], $s?->auto_assign_category_staff ?? [])];
                })
                ->modalWidth('2xl')
                ->schema([
                    Toggle::make('auto_assign')->label(__('নতুন টিকিট অটো assign হবে')),
                    Select::make('auto_assign_area_categories')->label(__('এই ক্যাটাগরিগুলো এলাকার আগের কর্মী পাবেন'))
                        ->multiple()->searchable()->options(fn () => TicketActions::options('categories'))
                        ->helperText(__('যেমন Line Off, Speed Slow। এখানে বা নিচের লিস্টে নেই এমন ক্যাটাগরির টিকিট অটো assign হয় না।')),
                    Repeater::make('auto_assign_category_staff')->label(__('অন্য ক্যাটাগরি সবসময় এই কর্মীরা পাবেন'))
                        ->addActionLabel(__('ক্যাটাগরি যোগ করুন'))->defaultItems(0)->columns(2)
                        ->schema([
                            Select::make('category_id')->label(__('ক্যাটাগরি'))->required()->searchable()
                                ->options(fn () => TicketActions::options('categories')),
                            Select::make('employees')->label(__('কর্মী'))->required()->multiple()->searchable()
                                ->options(fn () => TicketActions::options('employees')),
                        ])
                        ->rule(fn ($get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                            $area = array_map('strval', (array) $get('auto_assign_area_categories'));
                            $seen = [];
                            $team = [];
                            foreach (StaffTeam::where('company_id', Filament::getTenant()->getKey())->get() as $t) {
                                foreach ($t->members as $m) {
                                    $team[(string) $m] = $t->id;
                                }
                            }
                            $names = TicketActions::options('categories');
                            foreach ((array) $value as $row) {
                                $cat = (string) ($row['category_id'] ?? '');
                                if ($cat === '') {
                                    continue;
                                }
                                if (in_array($cat, $area, true) || isset($seen[$cat])) {
                                    $fail(__(':cat একবারই দেওয়া যায় (এলাকা অনুযায়ী অথবা নির্দিষ্ট কর্মী)', ['cat' => $names[$cat] ?? $cat]));
                                }
                                $seen[$cat] = true;
                                $teams = collect($row['employees'] ?? [])->map(fn ($e) => $team[(string) $e] ?? null)->unique();
                                if ($teams->count() > 1) {
                                    $fail(__(':cat: এক টিকিটে শুধু একই টিমের কর্মী দেওয়া যায়', ['cat' => $names[$cat] ?? $cat]));
                                }
                            }
                        }),
                    Select::make('auto_assign_exclude')->label(__('এই কর্মীরা কখনো অটো assign পাবেন না'))->multiple()->searchable()
                        ->options(fn () => TicketActions::options('employees'))
                        ->helperText(__('যেমন মালিক বা অফিসের কমন লগইন; তাঁরা বাদ গেলে ওই এলাকার পরের কর্মী পাবেন')),
                ])
                ->action(function (array $data) use ($company) {
                    BotSetting::updateOrCreate(['company_id' => $company()], [
                        'auto_assign' => (bool) $data['auto_assign'],
                        'auto_assign_exclude' => array_map('strval', array_values($data['auto_assign_exclude'] ?? [])),
                        'auto_assign_area_categories' => array_map('strval', array_values($data['auto_assign_area_categories'] ?? [])),
                        'auto_assign_category_staff' => array_values(array_map(fn ($r) => ['category_id' => (string) $r['category_id'],
                            'employees' => array_map('strval', array_values($r['employees'] ?? []))], $data['auto_assign_category_staff'] ?? [])),
                    ]);
                    Notification::make()->success()->title(__('সেভ হয়েছে'))->send();
                }),
        ];
    }
}
