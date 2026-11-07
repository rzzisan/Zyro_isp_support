<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskConnection;
use App\Support\NewConnectionForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * নতুন সংযোগ (billing phase 5, plan 2.4খ): the Desk's new connection form. Saving sends the customer to billing
 * (POST /Customer/Add with the member's billing login; billing makes the PPP secret), then the engine reads the
 * router back (engine/desk_connection.py). Guarded by বিলিং নিয়ম → connection_control (off / test / all).
 * The form itself is App\Support\NewConnectionForm (also the "নতুন কাস্টমার" button on the কাস্টমার list).
 * Owner, admins and members with the customer_create permission.
 */
class BillingNewConnections extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $slug = 'billing-new-connections';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|Htmlable
    {
        return __('নতুন সংযোগ (বিলিং-এও তৈরি হয়)');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && (in_array(auth()->user()?->roleIn($company), ['owner', 'admin'], true) || NewConnectionForm::canCreate());
    }

    public function getSubheading(): ?string
    {
        $s = BillingSetting::forCompany(Filament::getTenant());
        $mode = $s->connection_control ?? 'off';

        return __('এখন: :m.', ['m' => __(DeskConnection::CONTROL[$mode] ?? $mode)])
            .($mode === 'test' ? ' '.__('পরীক্ষার username শুরু: :p।', ['p' => $s->connection_test_prefix ?: '—']) : '')
            .' '.__('সেভ করলে কাস্টমার বিলিং-এ তৈরি হয়, বিলিং রাউটারে PPP secret বানায়, তারপর Desk রাউটার পড়ে profile আর চালু/বন্ধ মেলায়। PPPoE পাসওয়ার্ড Desk-এ রাখা হয় না, শুধু ফর্মে একবার দেখায়।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rules')->label(__('বিলিং নিয়ম'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->visible(fn () => BillingRules::canAccess())->url(fn () => BillingRules::getUrl()),
            NewConnectionForm::action('create')->visible(fn () => NewConnectionForm::canCreate()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(DeskConnection::query()->with('user')->where('company_id', Filament::getTenant()->getKey()))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label(__('কখন'))->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M, g:i A'))
                    ->description(fn (DeskConnection $r) => $r->user?->name ?? $r->actor),
                TextColumn::make('customer_code')->label('ID')->placeholder('—')
                    ->url(fn (DeskConnection $r) => $r->header_id && ($b = BillingCustomer::where('company_id', $r->company_id)->where('header_id', $r->header_id)->first())
                        ? CustomerResource::getUrl('view', ['record' => $b]) : null)
                    ->description(fn (DeskConnection $r) => $r->username),
                TextColumn::make('name')->label(__('নাম'))->description(fn (DeskConnection $r) => $r->mobile),
                TextColumn::make('package_name')->label(__('প্যাকেজ'))->placeholder('—')
                    ->description(fn (DeskConnection $r) => trim(($r->status_name ?? '').' · ৳'.number_format((float) $r->monthly_bill))),
                TextColumn::make('server_name')->label(__('রাউটার / secret'))->placeholder('—')
                    ->description(fn (DeskConnection $r) => $r->secret_profile
                        ? $r->secret_profile.' · '.($r->secret_disabled ? __('বন্ধ') : __('চালু')) : __('secret দেখা যায়নি')),
                TextColumn::make('status')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskConnection::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskConnection::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskConnection $r) => $r->error),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('ফল'))->options(collect(DeskConnection::STATUSES)->map(fn ($r) => __($r[0]))->all()),
            ]);
    }
}
