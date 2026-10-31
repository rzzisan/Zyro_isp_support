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
                ->modalDescription(__('প্রতিটি কাজে এক বা একাধিক key বাছুন, টেনে ক্রম ঠিক করুন। প্রথমটি না চললে (quota, বিল, অন্য সমস্যা) পরেরটি দিয়ে চলবে; বাছা key-এর বাইরে কোনো key ব্যবহার হবে না। খালি রাখলে ওই ধরনের সব key ক্রমে চলবে।'))
                ->fillForm(fn () => DB::table('ai_key_jobs')->where('company_id', Filament::getTenant()->getKey())
                    ->orderBy('position')->orderBy('id')->get()->groupBy('job')
                    ->map(fn ($rows) => $rows->pluck('ai_key_id')->map(fn ($id) => (string) $id)->all())->all())
                ->schema(fn () => collect(AiKey::JOBS)->map(fn ($job, $name) => Select::make($name)->label(__($job[0]))
                    ->multiple()->reorderable()->placeholder(__('ওই ধরনের সব key'))
                    ->options(AiKey::where('company_id', Filament::getTenant()->getKey())->whereIn('provider', $job[1])
                        ->orderBy('id')->get()
                        ->mapWithKeys(fn (AiKey $k) => [$k->id => ($k->label ?: AiKey::PROVIDERS[$k->provider][0] ?? $k->provider)
                            .' ('.(AiKey::PROVIDERS[$k->provider][0] ?? $k->provider).')']))
                )->values()->all())
                ->action(function (array $data) {
                    $company = Filament::getTenant()->getKey();
                    $valid = AiKey::where('company_id', $company)->pluck('provider', 'id');
                    DB::transaction(function () use ($company, $data, $valid) {
                        foreach (AiKey::JOBS as $job => [, $providers]) {
                            DB::table('ai_key_jobs')->where('company_id', $company)->where('job', $job)->delete();
                            $keys = collect($data[$job] ?? [])->unique()
                                ->filter(fn ($key) => in_array($valid[$key] ?? null, $providers, true))->values();
                            DB::table('ai_key_jobs')->insert($keys->map(fn ($key, $position) => ['company_id' => $company,
                                'job' => $job, 'ai_key_id' => $key, 'position' => $position, 'updated_at' => now(), 'created_at' => now()])->all());
                        }
                    });
                    Notification::make()->success()->title(__('সংরক্ষণ হয়েছে; পরের কাজ থেকে চলবে'))->send();
                }),
        ];
    }
}
