<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\DeskCustomerChange;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use RuntimeException;

/**
 * Billing phase 5 (plan 2.4খ): PPP secret check (every router's secrets against billing's customers: no secret,
 * secret without a customer, a Left customer's secret still there, profile not billing's Speed) and the log of
 * changes made from the কাস্টমার page (package, Left / back, password; engine/desk_change.py). Owner and admins.
 */
class BillingSecretCheck extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'billing-secret-check';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.billing-secret-check';

    public ?array $audit = null;

    public ?string $auditError = null;

    public function getTitle(): string|Htmlable
    {
        return __('PPP secret যাচাই ও কাস্টমার বদলের লগ');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && in_array(auth()->user()?->roleIn($company), ['owner', 'admin'], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rules')->label(__('বিলিং নিয়ম'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->visible(fn () => BillingRules::canAccess())->url(fn () => BillingRules::getUrl()),
            Action::make('runAudit')->label(__('রাউটার মিলিয়ে দেখুন'))->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => $this->runAudit()),
        ];
    }

    public function runAudit(): void
    {
        try {
            $this->audit = Engine::secretAudit(Filament::getTenant()->getKey());
            $this->auditError = null;
        } catch (RuntimeException $e) {
            $this->audit = null;
            $this->auditError = $e->getMessage();
        }
    }

    public function removeSecret(int $headerId): void
    {
        try {
            $r = Engine::customerChange(Filament::getTenant()->getKey(), 'remove', $headerId, []);
            Notification::make()->{($r['status'] ?? '') === 'done' ? 'success' : 'warning'}()
                ->title(__(DeskCustomerChange::STATUSES[$r['status'] ?? 'failed'][0] ?? ''))
                ->body(trim(($r['note'] ?? '').' '.($r['error'] ?? '')) ?: null)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('হয়নি'))->body($e->getMessage())->send();
        }
        $this->runAudit();
    }

    public function customerUrl(?int $headerId): ?string
    {
        $b = $headerId ? BillingCustomer::where('company_id', Filament::getTenant()->getKey())->where('header_id', $headerId)->first() : null;

        return $b ? CustomerResource::getUrl('view', ['record' => $b]) : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('কাস্টমার বদলের লগ (প্যাকেজ, Left, ফেরত, পাসওয়ার্ড)'))
            ->query(DeskCustomerChange::query()->with('user')->where('company_id', Filament::getTenant()->getKey()))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label(__('কখন'))->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M, g:i A'))
                    ->description(fn (DeskCustomerChange $r) => $r->user?->name ?? $r->actor),
                TextColumn::make('customer_code')->label('ID')->placeholder('—')
                    ->url(fn (DeskCustomerChange $r) => $this->customerUrl($r->header_id))
                    ->description(fn (DeskCustomerChange $r) => $r->username),
                TextColumn::make('kind')->label(__('কাজ'))->formatStateUsing(fn ($state) => __(DeskCustomerChange::KINDS[$state] ?? $state))
                    ->description(fn (DeskCustomerChange $r) => collect($r->detail ?? [])->only(['package_name', 'profile', 'monthly_bill', 'status_name'])
                        ->filter()->implode(' · ')),
                TextColumn::make('secret_profile')->label(__('রাউটার / secret'))->placeholder('—')
                    ->description(fn (DeskCustomerChange $r) => $r->note),
                TextColumn::make('status')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskCustomerChange::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskCustomerChange::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskCustomerChange $r) => $r->error),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('কাজ'))->options(collect(DeskCustomerChange::KINDS)->map(fn ($l) => __($l))->all()),
                SelectFilter::make('status')->label(__('ফল'))->options(collect(DeskCustomerChange::STATUSES)->map(fn ($r) => __($r[0]))->all()),
            ]);
    }
}
