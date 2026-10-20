<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * "আমার প্রোফাইল" in the user menu (both panels): a member changes their own name and password.
 * The email is the login and is set by the company owner on the Team page, so it is shown read-only here.
 * A new password needs the current one, keeps this browser signed in, and signs out every other browser
 * (AuthenticateSession) and every phone (the app's tokens are deleted).
 */
class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        return false;
    }

    public static function getLabel(): string
    {
        return __('আমার প্রোফাইল ও পাসওয়ার্ড');
    }

    public function getTitle(): string|Htmlable
    {
        return __('আমার প্রোফাইল ও পাসওয়ার্ড');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent()->label(__('নাম')),
            TextInput::make('email')->label(__('ইমেইল (লগইন)'))->disabled()->dehydrated(false)
                ->helperText(__('ইমেইল বদলাতে কোম্পানির Owner/Admin-কে বলুন')),
            $this->getPasswordFormComponent()->label(__('নতুন পাসওয়ার্ড'))->validationAttribute(__('নতুন পাসওয়ার্ড'))
                ->rule('min:8')->helperText(__('অন্তত ৮ অক্ষর। বদলাতে না চাইলে খালি রাখুন')),
            $this->getPasswordConfirmationFormComponent()->label(__('নতুন পাসওয়ার্ড আবার লিখুন'))
                ->validationAttribute(__('নতুন পাসওয়ার্ড আবার লিখুন')),
            $this->getCurrentPasswordFormComponent()->label(__('বর্তমান পাসওয়ার্ড'))->validationAttribute(__('বর্তমান পাসওয়ার্ড'))
                ->belowContent(__('পাসওয়ার্ড বদলাতে বর্তমান পাসওয়ার্ড দিন')),
        ]);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        // the email can't change here, so only a new password asks for the current one
        return parent::getCurrentPasswordFormComponent()->visible(fn ($get): bool => filled($get('password')));
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $record->update($data);
        if (array_key_exists('password', $data)) {
            $record->tokens()->delete();
        }

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('সেভ হয়েছে');
    }
}
