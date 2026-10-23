<?php

namespace App\Filament\App\Resources\StaffTeams\Pages;

use App\Filament\App\Resources\StaffTeams\StaffTeamResource;
use App\Filament\App\TicketActions;
use App\Models\BotSetting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageStaffTeams extends ManageRecords
{
    protected static string $resource = StaffTeamResource::class;

    protected function getHeaderActions(): array
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        // create lives in the table header (sets the company); here the app's field work: target time and line check per category
        return [
            Action::make('fieldSettings')->label(__('ফিল্ড কাজ সেটিং'))->icon(Heroicon::OutlinedClock)
                ->fillForm(function () use ($company) {
                    $s = BotSetting::where('company_id', $company())->first();

                    return ['field_job_minutes' => $s?->field_job_minutes ?? 90,
                        'field_job_targets' => array_map(fn ($r) => ['category_id' => (string) $r['category_id'],
                            'minutes' => $r['minutes'] ?? null, 'line_check' => (bool) ($r['line_check'] ?? true)], $s?->field_job_targets ?? [])];
                })
                ->modalWidth('2xl')
                ->schema([
                    TextInput::make('field_job_minutes')->label(__('কাজ শেষ করার সময় (মিনিট), সব ক্যাটাগরি'))->numeric()
                        ->minValue(5)->maxValue(1440)->required()
                        ->helperText(__('অ্যাপে "কাজ শুরু করুন" চাপলে এই সময় থেকে কাউন্টডাউন চলে; পার হলে দেরি গোনে।')),
                    Repeater::make('field_job_targets')->label(__('ক্যাটাগরি অনুযায়ী আলাদা'))
                        ->addActionLabel(__('ক্যাটাগরি যোগ করুন'))->defaultItems(0)->columns(3)
                        ->schema([
                            Select::make('category_id')->label(__('ক্যাটাগরি'))->required()->searchable()
                                ->options(fn () => TicketActions::options('categories')),
                            TextInput::make('minutes')->label(__('মিনিট'))->numeric()->minValue(5)->maxValue(1440)->required(),
                            Toggle::make('line_check')->label(__('Online হলে তবেই শেষ'))->default(true)->inline(false),
                        ]),
                ])
                ->action(function (array $data) use ($company) {
                    BotSetting::updateOrCreate(['company_id' => $company()], [
                        'field_job_minutes' => (int) $data['field_job_minutes'],
                        'field_job_targets' => array_values(array_map(fn ($r) => ['category_id' => (string) $r['category_id'],
                            'minutes' => (int) $r['minutes'], 'line_check' => (bool) ($r['line_check'] ?? true)], $data['field_job_targets'] ?? [])),
                    ]);
                    Notification::make()->success()->title(__('সেভ হয়েছে'))->send();
                }),
        ];
    }
}
