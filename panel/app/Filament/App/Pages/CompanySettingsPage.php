<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** A settings page that edits one row belonging to the current company. Owner/Admin only. */
abstract class CompanySettingsPage extends Page
{
    public ?array $data = [];

    abstract protected function record(Company $company): Model;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill($this->fillData($this->record(Filament::getTenant())));
    }

    protected function fillData(Model $record): array
    {
        return $record->attributesToArray();
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        return $data;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $record = $this->record(Filament::getTenant());
        $data = $this->beforeSaving($this->form->getState(), $record);
        $record->fill($data)->save();
        Notification::make()->success()->title('সেভ হয়েছে')->send();
        $this->afterSaved($record);
    }

    protected function afterSaved(Model $record): void {}

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('সেভ করুন')->submit('save')]),
                ]),
        ]);
    }
}
