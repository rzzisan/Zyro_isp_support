<?php

namespace App\Filament\App\Resources\AiKeys\Pages;

use App\Filament\App\Resources\AiKeys\AiKeyResource;
use App\Models\AiKey;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ManageAiKeys extends ManageRecords
{
    protected static string $resource = AiKeyResource::class;

    protected function getHeaderActions(): array
    {
        // create lives in the table header (with seat/role checks)
        return [
            Action::make('jobs')->label(__('কোন কাজে কোন key'))->icon(Heroicon::OutlinedCog)->color('gray')
                ->modalDescription(__('খালি রাখলে ওই কাজে আগের নিয়মে চলবে (ওই ধরনের প্রথম key)। বেছে নেওয়া key না চললে একই ধরনের পরের key দিয়ে চলবে।'))
                ->fillForm(fn () => DB::table('ai_key_jobs')->where('company_id', Filament::getTenant()->getKey())
                    ->pluck('ai_key_id', 'job')->all())
                ->schema(fn () => collect(AiKey::JOBS)->map(fn ($job, $name) => Select::make($name)->label(__($job[0]))
                    ->placeholder(__('আগের নিয়মে'))
                    ->options(AiKey::where('company_id', Filament::getTenant()->getKey())->whereIn('provider', $job[1])
                        ->orderBy('id')->get()
                        ->mapWithKeys(fn (AiKey $k) => [$k->id => ($k->label ?: AiKey::PROVIDERS[$k->provider][0] ?? $k->provider)
                            .' ('.(AiKey::PROVIDERS[$k->provider][0] ?? $k->provider).')']))
                )->values()->all())
                ->action(function (array $data) {
                    $company = Filament::getTenant()->getKey();
                    $valid = AiKey::where('company_id', $company)->pluck('provider', 'id');
                    foreach (AiKey::JOBS as $job => [, $providers]) {
                        $key = $data[$job] ?? null;
                        if ($key && in_array($valid[$key] ?? null, $providers, true)) {
                            DB::table('ai_key_jobs')->updateOrInsert(['company_id' => $company, 'job' => $job],
                                ['ai_key_id' => $key, 'updated_at' => now(), 'created_at' => now()]);
                        } else {
                            DB::table('ai_key_jobs')->where('company_id', $company)->where('job', $job)->delete();
                        }
                    }
                    Notification::make()->success()->title(__('সংরক্ষণ হয়েছে; পরের কাজ থেকে চলবে'))->send();
                }),
        ];
    }
}
