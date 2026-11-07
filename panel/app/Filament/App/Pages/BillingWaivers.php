<?php

namespace App\Filament\App\Pages;

use App\Models\BillingSetting;
use App\Models\DeskWaiver;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * বিল মাফ (billing phase 4c, plan 2.2 #2): whom the Desk waives for a month the line stayed off the whole time and no
 * money came. While billing is the master only a list (shadow); with the Desk as main billing it is in the ledger.
 */
class BillingWaivers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $slug = 'billing-waivers';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|Htmlable
    {
        return __('বিল মাফ: লাইন পুরো মাস বন্ধ');
    }

    public static function canAccess(): bool
    {
        return BillingLedger::canAccess();
    }

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant();
        $s = BillingSetting::forCompany($company);
        $last = DB::table('desk_waivers')->where('company_id', $company->getKey())->max('period_start');
        $n = $last ? DB::table('desk_waivers')->where('company_id', $company->getKey())->where('period_start', $last)
            ->selectRaw("count(*) FILTER (WHERE status <> 'pending') AS n, coalesce(sum(amount) FILTER (WHERE status <> 'pending'), 0) AS a, count(*) FILTER (WHERE status = 'pending') AS p")->first() : null;

        return ($s->waive_off_month === false ? __('নিয়মটা বিলিং নিয়ম পেজে বন্ধ আছে।').' ' : '')
            .($n ? __(':m: :n জন, :a টাকা।', ['m' => Carbon::parse($last)->format('M Y'), 'n' => number_format($n->n), 'a' => number_format((float) $n->a)])
                .($n->p ? ' '.__(':p জনের লাইনের লগ বিলিং থেকে আসা বাকি, পরের রাতে আবার দেখা হবে।', ['p' => number_format($n->p)]) : '').' ' : '')
            .__('যে মাসে লাইন একবারও চালু হয়নি (হাতে, বট বা সময় বাড়িয়ে) আর কোনো টাকা আসেনি, সে মাসের বিল শূন্য। আংশিক পেমেন্টের মাস আর আগের বকেয়া থাকে। মাস শেষে প্রতি রাতে দেখা হয়।')
            .' '.(($s->billing_mode ?? 'ispdigital') === 'desk'
                ? __('Desk মূল বিলিং, তাই মাফ খাতায় লেখা হয়।')
                : __('বিলিং সফটওয়্যার এখনো মূল, তাই এটা শুধু তালিকা: খাতায় বা বিলিং-এ কিছু বদলায় না।'));
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();
        $periods = DB::table('desk_waivers')->where('company_id', $company->getKey())->distinct()->orderByDesc('period_start')
            ->limit(24)->pluck('period_start')->mapWithKeys(fn ($p) => [$p => Carbon::parse($p)->format('M Y')])->all();

        return $table
            ->query(DeskWaiver::query()->with('customer')->where('company_id', $company->getKey()))
            ->defaultSort('id', 'desc')
            ->deferFilters(false)
            ->paginated([50, 100, 500])
            ->columns([
                TextColumn::make('customer.customer_code')->label('ID')->searchable()
                    ->url(fn (DeskWaiver $r) => BillingLedger::customerUrl($r->customer))
                    ->description(fn (DeskWaiver $r) => $r->customer?->name),
                TextColumn::make('period_start')->label(__('মাস'))->date('M Y')->sortable(),
                TextColumn::make('amount')->label(__('বিল'))->numeric(0)->sortable(),
                TextColumn::make('off_since')->label(__('লাইন বন্ধ যেদিন থেকে'))->placeholder(__('লগে নেই')),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskWaiver::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskWaiver::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskWaiver $r) => $r->applied_at?->timezone('Asia/Dhaka')->format('d M Y')),
            ])
            ->filters([
                SelectFilter::make('period_start')->label(__('মাস'))->options($periods)->default(array_key_first($periods)),
                SelectFilter::make('status')->label(__('অবস্থা'))->options(collect(DeskWaiver::STATUSES)->map(fn ($s) => __($s[0]))->all()),
            ]);
    }
}
