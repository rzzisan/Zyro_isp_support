<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\SipSetting;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * The hotline's SIP extension (cloud PBX). Put it in the hotline's ring group: while a call rings, the panel shows
 * the caller and their customer profile. It only listens and never answers. Owner/Admin only.
 */
class SipSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    public static function getNavigationLabel(): string
    {
        return __('হটলাইন (SIP)');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('হটলাইন (SIP)');
    }

    protected static ?string $slug = 'hotline-sip';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 7;

    private const NO_AUTOFILL = ['autocomplete' => 'new-password', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true'];

    protected function record(Company $company): Model
    {
        return SipSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        /** @var SipSetting $record */
        return ['server' => $record->server, 'port' => $record->port ?? 5060, 'transport' => $record->transport ?? 'udp',
            'username' => $record->username, 'auth_user' => $record->auth_user, 'password' => null, 'enabled' => $record->enabled];
    }

    public function form(Schema $schema): Schema
    {
        $s = $this->record(Filament::getTenant());

        return $schema->components([
            Placeholder::make('how')->hiddenLabel()
                ->content(__('PBX-এ একটা আলাদা এক্সটেনশন বানিয়ে হটলাইনের রিং গ্রুপে যোগ করুন, তারপর এখানে তার তথ্য দিন। কল এলে এটা শুধু বাজে, কখনো ধরে না; সেই সময় Desk-এ কলারের নম্বর আর কাস্টমার প্রোফাইল ভেসে ওঠে।')),
            Grid::make(3)->schema([
                TextInput::make('server')->label(__('SIP সার্ভার'))->required()->maxLength(200)
                    ->placeholder('pbx.example.com')
                    ->rule('regex:/^[A-Za-z0-9.\-]+$/')->validationMessages(['regex' => __('শুধু ডোমেইন বা IP দিন (sip: বা পোর্ট ছাড়া)')]),
                TextInput::make('port')->label(__('পোর্ট'))->required()->integer()->minValue(1)->maxValue(65535),
                Select::make('transport')->label(__('ট্রান্সপোর্ট'))->required()->selectablePlaceholder(false)
                    ->options(['udp' => 'UDP', 'tcp' => 'TCP']),
                TextInput::make('username')->label(__('এক্সটেনশন / ইউজারনেম'))->required()->maxLength(80)
                    ->rule('regex:/^[A-Za-z0-9._\-+]+$/'),
                TextInput::make('auth_user')->label(__('Auth ইউজার (ঐচ্ছিক)'))->maxLength(80)
                    ->placeholder(__('খালি = এক্সটেনশনই'))->rule('regex:/^[A-Za-z0-9._\-+@]+$/'),
                // the browser would autofill the Desk login here; this is the PBX extension's own password
                TextInput::make('password')->label(__('পাসওয়ার্ড'))->password()->revealable()->maxLength(120)->extraInputAttributes(self::NO_AUTOFILL)
                    ->required(! filled($s->password))
                    ->rule('regex:/^[^;<>\s"]+$/')->validationMessages(['regex' => __('পাসওয়ার্ডে ; < > " বা স্পেস থাকলে চলবে না; PBX-এ পাসওয়ার্ড বদলে নিন')])
                    ->helperText(filled($s->password) ? __('এনক্রিপ্ট করে রাখা আছে; বদলাতে না চাইলে খালি রাখুন') : __('এনক্রিপ্ট করে রাখা হবে, পরে আর দেখা যাবে না')),
            ]),
            Toggle::make('enabled')->label(__('কল পপআপ চালু'))
                ->helperText(__('চালু করলে এক মিনিটের মধ্যে এক্সটেনশন রেজিস্টার হয়; বন্ধ করলে রেজিস্ট্রেশন ছেড়ে দেয়')),
            Placeholder::make('status')->label(__('অবস্থা'))
                ->content(fn () => $s->status_at
                    ? ($s->status === 'registered' ? __('✅ রেজিস্টার্ড') : '❌ '.$s->status).' · '.$s->status_at->diffForHumans()
                    : __('এখনো রেজিস্টার হয়নি')),
        ]);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $data['server'] = trim($data['server']);
        $data['username'] = trim($data['username']);
        $data['auth_user'] = filled($data['auth_user'] ?? null) ? trim($data['auth_user']) : null;
        $data['company_id'] = Filament::getTenant()->id;
        // the engine re-registers within a minute; the old status no longer applies
        $data['status'] = null;
        $data['status_at'] = null;

        return $data;
    }
}
