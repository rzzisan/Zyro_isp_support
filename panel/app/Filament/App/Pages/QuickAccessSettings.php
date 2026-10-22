<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Support\QuickAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/** Owner picks which menus sit in the sidebar's "কুইক অ্যাক্সেস" group (companies.quick_access). */
class QuickAccessSettings extends CompanySettingsPage
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $slug = 'quick-access';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return __('কুইক অ্যাক্সেস');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('কুইক অ্যাক্সেস');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && auth()->user()?->roleIn($company) === 'owner';
    }

    protected function record(Company $company): Model
    {
        return $company;
    }

    protected function fillData(Model $record): array
    {
        return ['quick_access' => QuickAccess::classes($record)];
    }

    public function form(Schema $schema): Schema
    {
        $options = QuickAccess::options();

        return $schema->components([
            CheckboxList::make('quick_access')->label(__('সাইডবারের উপরে কুইক অ্যাক্সেসে যে মেনুগুলো থাকবে'))
                ->helperText(__('টিক দেওয়া মেনু নিজের গ্রুপ থেকে কুইক অ্যাক্সেসে চলে যাবে। যার যে মেনুর অনুমতি নেই, সে সেটা কুইক অ্যাক্সেসেও দেখবে না।'))
                ->options($options)
                ->descriptions(collect($options)->mapWithKeys(fn ($label, $class) => [$class => QuickAccess::homeGroupLabel($class)])->all())
                ->columns(3)->bulkToggleable(),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        return ['quick_access' => QuickAccess::ordered($data['quick_access'] ?? [], QuickAccess::classes($record))];
    }

    protected function afterSaved(Model $record): void
    {
        // the sidebar is drawn before this request saved, so reload to show the new quick access
        $this->redirect(static::getUrl());
    }
}
