<?php

namespace App\Filament\App\Resources\WalkieGroups\Pages;

use App\Filament\App\Resources\WalkieClips\WalkieClipResource;
use App\Filament\App\Resources\WalkieGroups\WalkieGroupResource;
use App\Models\BotSetting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageWalkieGroups extends ManageRecords
{
    protected static string $resource = WalkieGroupResource::class;

    protected function getHeaderActions(): array
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;

        // create lives in the table header (sets the company)
        return [
            Action::make('recordings')->label(__('রেকর্ড'))->icon(Heroicon::OutlinedMicrophone)->color('gray')
                ->url(fn () => WalkieClipResource::getUrl()),
            Action::make('walkieSettings')->label(__('সময় ও রেকর্ড সেটিং'))->icon(Heroicon::OutlinedClock)
                ->fillForm(function () use ($company) {
                    $s = BotSetting::where('company_id', $company())->first();

                    return ['walkie_on_at' => $s?->walkie_on_at ?? '10:00', 'walkie_off_at' => $s?->walkie_off_at,
                        'walkie_keep_days' => $s?->walkie_keep_days ?? 90];
                })
                ->schema([
                    TextInput::make('walkie_on_at')->label(__('প্রতিদিন নিজে থেকে চালু'))->type('time')->required(),
                    TextInput::make('walkie_off_at')->label(__('প্রতিদিন নিজে থেকে বন্ধ (খালি = বন্ধ হবে না)'))->type('time'),
                    TextInput::make('walkie_keep_days')->label(__('রেকর্ড কত দিন থাকবে'))->numeric()->minValue(7)->maxValue(730)->required(),
                ])
                ->action(function (array $data) use ($company) {
                    BotSetting::updateOrCreate(['company_id' => $company()], [
                        'walkie_on_at' => substr((string) $data['walkie_on_at'], 0, 5),
                        'walkie_off_at' => filled($data['walkie_off_at'] ?? null) ? substr((string) $data['walkie_off_at'], 0, 5) : null,
                        'walkie_keep_days' => (int) $data['walkie_keep_days'],
                    ]);
                    Notification::make()->success()->title(__('সেভ হয়েছে'))->send();
                }),
        ];
    }
}
