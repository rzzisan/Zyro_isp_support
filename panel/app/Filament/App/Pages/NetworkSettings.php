<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/** Company-wide network options; for now the port of the customers' routers, used to link their IP in tickets and the inbox. */
class NetworkSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|\UnitEnum|null $navigationGroup = 'নেটওয়ার্ক';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('নেটওয়ার্ক সেটিংস');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('নেটওয়ার্ক সেটিংস');
    }

    public static function canAccess(): bool
    {
        return parent::canAccess() && \App\Support\Menu::can('mikrotik');
    }

    protected function record(Company $company): Model
    {
        return $company;
    }

    protected function fillData(Model $record): array
    {
        return ['router_port' => $record->router_port];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('router_port')->label(__('কাস্টমারের রাউটারের রিমোট পোর্ট'))
                ->integer()->minValue(1)->maxValue(65535)->placeholder('7863')
                ->helperText(__('টিকিট ও ইনবক্সে কাস্টমারের IP-তে ক্লিক করলে নতুন ট্যাবে তার রাউটার খুলবে (যেমন http://IP:7863)। খালি রাখলে শুধু http://IP খুলবে।')),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        return ['router_port' => filled($data['router_port'] ?? null) ? (int) $data['router_port'] : null];
    }
}
