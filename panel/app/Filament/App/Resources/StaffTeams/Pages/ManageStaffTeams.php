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
                            'minutes' => $r['minutes'] ?? null, 'line_check' => (bool) ($r['line_check'] ?? true)], $s?->field_job_targets ?? []),
                        'field_office_lat' => $s?->field_office_lat, 'field_office_lng' => $s?->field_office_lng,
                        'field_office_radius' => $s?->field_office_radius ?? 20,
                        'field_duty_start' => $s?->field_duty_start ?? '10:00', 'field_duty_end' => $s?->field_duty_end ?? '17:00'];
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
                    \Filament\Schemas\Components\Fieldset::make(__('অটো ডিউটি'))->columns(3)->schema([
                        TextInput::make('field_office_lat')->label(__('অফিসের Latitude'))->numeric()->minValue(-90)->maxValue(90),
                        TextInput::make('field_office_lng')->label(__('অফিসের Longitude'))->numeric()->minValue(-180)->maxValue(180),
                        TextInput::make('field_office_radius')->label(__('অফিসের কত মিটারের মধ্যে'))->numeric()->minValue(10)->maxValue(500)->required(),
                        TextInput::make('field_duty_start')->label(__('ডিউটি শুরু (এর পরে অফিসে এলে)'))->type('time')->required(),
                        TextInput::make('field_duty_end')->label(__('ডিউটি শেষ'))->type('time')->required(),
                    ])->columnSpanFull(),
                    \Filament\Forms\Components\Placeholder::make('duty_help')->hiddenLabel()->columnSpanFull()
                        ->content(__('Google Maps-এ অফিসের উপর চেপে ধরলে Latitude, Longitude দেখায়। শুরুর সময়ের পর টেকনিশিয়ানের ফোন অফিসের এই দূরত্বের মধ্যে এলে ডিউটি নিজে শুরু হয়; শেষের সময়ে, বা দিনের সব কাজ শেষ হলে আগেই, ডিউটি শেষ হয়।')),
                ])
                ->action(function (array $data) use ($company) {
                    BotSetting::updateOrCreate(['company_id' => $company()], [
                        'field_job_minutes' => (int) $data['field_job_minutes'],
                        'field_job_targets' => array_values(array_map(fn ($r) => ['category_id' => (string) $r['category_id'],
                            'minutes' => (int) $r['minutes'], 'line_check' => (bool) ($r['line_check'] ?? true)], $data['field_job_targets'] ?? [])),
                        'field_office_lat' => filled($data['field_office_lat'] ?? null) ? (float) $data['field_office_lat'] : null,
                        'field_office_lng' => filled($data['field_office_lng'] ?? null) ? (float) $data['field_office_lng'] : null,
                        'field_office_radius' => (int) $data['field_office_radius'],
                        'field_duty_start' => substr((string) $data['field_duty_start'], 0, 5), 'field_duty_end' => substr((string) $data['field_duty_end'], 0, 5),
                    ]);
                    Notification::make()->success()->title(__('সেভ হয়েছে'))->send();
                }),
        ];
    }
}
