<?php

namespace App\Filament\App\Pages;

use App\Models\BillingCustomer;
use App\Models\DeskCustomerBalance;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * বকেয়ার অমিল (billing phase 1): customers whose due in the Desk ledger doesn't match billing's after the last nightly
 * copy, with a likely reason and since which morning. Read only, owner only.
 */
class BillingDueMatch extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $slug = 'billing-due-match';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('বকেয়ার অমিল: Desk বনাম বিলিং');
    }

    public function getSubheading(): ?string
    {
        return __('প্রতিদিন সকাল ৭:১৫-এর কপির পর Desk-এর খাতার বকেয়া বিলিং-এর বকেয়ার সাথে মেলানো হয়। এখানে যাদের ১ টাকার বেশি তফাত। "বিলিং-এর নতুন রিসিট" কাল নিজেই মিলে যায়।');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && auth()->user()?->roleIn($company) === 'owner';
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(DeskCustomerBalance::query()->with('customer')->where('company_id', $company->getKey())
                ->whereNotNull('diff')->where('has_history', true))
            ->defaultSort('mismatch_since')
            ->deferFilters(false)
            ->paginated([50, 100, 500])
            ->columns([
                TextColumn::make('customer.customer_code')->label('ID')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('customer', fn (Builder $c) => $c
                        ->where('customer_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                    ->url(fn (DeskCustomerBalance $r) => ($b = BillingCustomer::where('company_id', $r->company_id)
                        ->where('header_id', $r->customer?->header_id)->first())
                        ? \App\Filament\App\Resources\Customers\CustomerResource::getUrl('view', ['record' => $b]) : null),
                TextColumn::make('customer.name')->label(__('নাম'))->limit(24),
                TextColumn::make('customer.status')->label('Status'),
                TextColumn::make('balance')->label(__('Desk-এর বকেয়া'))->numeric(0)->sortable(),
                TextColumn::make('billing_due')->label(__('বিলিং-এর বকেয়া'))->numeric(0)->sortable(),
                TextColumn::make('diff')->label(__('তফাত'))->numeric(0)->sortable(),
                TextColumn::make('mismatch_reason')->label(__('সম্ভাব্য কারণ'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskCustomerBalance::REASONS[$state][0] ?? $state))
                    ->color(fn ($state) => DeskCustomerBalance::REASONS[$state][1] ?? 'gray')
                    ->description(fn (DeskCustomerBalance $r) => $r->recent_receipts ? __('নতুন রিসিট :a', ['a' => number_format((float) $r->recent_receipts)]) : null),
                TextColumn::make('mismatch_since')->label(__('কবে থেকে'))->date('d M')->sortable(),
                TextColumn::make('last_payment_on')->label(__('শেষ পেমেন্ট'))->date('d M')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('mismatch_reason')->label(__('সম্ভাব্য কারণ'))
                    ->options(collect(DeskCustomerBalance::REASONS)->map(fn ($r) => __($r[0]))->all()),
                TernaryFilter::make('excluded')->label(__('মেলানো থেকে বাদ দেওয়া ID'))->default(false),
            ]);
    }
}
