<?php

namespace App\Filament\Super\Resources\Companies\Pages;

use App\Filament\Super\Resources\Companies\CompanyResource;
use App\Models\Plan;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected array $owner = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->owner = [
            'name' => $data['owner_name'] ?? null,
            'email' => $data['owner_email'] ?? null,
            'password' => $data['owner_password'] ?? null,
        ];
        unset($data['owner_name'], $data['owner_email'], $data['owner_password']);

        if ($this->owner['email'] && ! User::where('email', $this->owner['email'])->exists()
            && (blank($this->owner['password']) || blank($this->owner['name']))) {
            throw ValidationException::withMessages([
                'data.owner_password' => 'নতুন Owner-এর জন্য নাম আর পাসওয়ার্ড দিন।',
            ]);
        }

        if (empty($data['trial_ends_at']) && ! empty($data['plan_id'])) {
            $days = Plan::find($data['plan_id'])?->trial_days ?? 0;
            if ($days > 0) {
                $data['trial_ends_at'] = now()->addDays($days);
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->owner['email']) {
            return;
        }
        $user = User::where('email', $this->owner['email'])->first()
            ?? User::create([
                'name' => $this->owner['name'],
                'email' => $this->owner['email'],
                'password' => $this->owner['password'],
            ]);
        $this->record->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
    }
}
