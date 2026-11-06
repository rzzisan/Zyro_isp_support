<?php

namespace App\Filament\App\Resources\OfficePhones\Pages;

use App\Filament\App\Resources\OfficePhones\OfficePhoneResource;
use App\Models\SipSetting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageOfficePhones extends ManageRecords
{
    protected static string $resource = OfficePhoneResource::class;

    public function getSubheading(): ?string
    {
        return __('ফোন আমাদের সার্ভারে রেজিস্টার হয়, আর আমাদের সার্ভার তার জায়গায় pbx.bd-তে। তাই সব কল রেকর্ড হয় আর কল লগে ওঠে। নম্বর আর রিং গ্রুপ আগের মতোই থাকে।');
    }

    protected function getHeaderActions(): array
    {
        return [
            // the networks office phones may register from; anything else is refused by Asterisk (endpoint ACL)
            Action::make('allowedIps')->label(__('অনুমতির IP তালিকা'))->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                ->modalDescription(__('শুধু এই IP বা নেটওয়ার্ক থেকে অফিস ফোন আমাদের সার্ভারে রেজিস্টার হতে পারবে। একটা IP (যেমন 10.90.90.90) বা পুরো নেটওয়ার্ক (যেমন 10.90.90.0/24) দিন।'))
                ->fillForm(fn () => ['ips' => SipSetting::forCompany(Filament::getTenant())->officeAllowedIps()])
                ->schema([
                    Repeater::make('ips')->hiddenLabel()->addActionLabel(__('IP যোগ করুন'))->columns(2)->defaultItems(0)
                        ->schema([
                            TextInput::make('net')->label(__('IP / নেটওয়ার্ক'))->required()->maxLength(43)
                                ->rule(fn () => function (string $attribute, $value, $fail) {
                                    if (! SipSetting::validNet((string) $value)) {
                                        $fail(__('সঠিক IP বা নেটওয়ার্ক দিন, যেমন 10.90.90.0/24'));
                                    }
                                }),
                            TextInput::make('note')->label(__('কোথাকার'))->maxLength(60),
                        ]),
                ])
                ->action(function (array $data) {
                    $s = SipSetting::forCompany(Filament::getTenant());
                    $s->office_allowed_ips = json_encode(collect($data['ips'] ?? [])
                        ->map(fn ($r) => ['net' => trim($r['net']), 'note' => trim((string) ($r['note'] ?? ''))])
                        ->unique('net')->values()->all());
                    $s->company_id = Filament::getTenant()->getKey();
                    $s->save();
                    OfficePhoneResource::kick();
                    Notification::make()->success()->title(__('সেভ হয়েছে; এক মিনিটের মধ্যে চালু হবে'))->send();
                }),
        ];
    }
}
