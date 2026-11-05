<?php

namespace App\Filament\App\Pages;

use App\Models\BillingCustomer;
use App\Models\DeskShadowBill;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * মাসিক বিল মিল (billing phase 1): the Desk makes its own monthly bills on the 1st (shadow only: not in the ledger, not
 * in billing) and compares them every night with billing's bill of the same month. Owner only.
 */
class BillingShadowBills extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $slug = 'billing-shadow-bills';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('মাসিক বিল মিল: Desk-এর ছায়া বিল বনাম বিলিং');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && auth()->user()?->roleIn($company) === 'owner';
    }

    /** The months made, newest first. */
    public function periods(): array
    {
        return DB::table('desk_shadow_bills')->where('company_id', Filament::getTenant()->getKey())
            ->select('period_start')->distinct()->orderByDesc('period_start')->pluck('period_start')
            ->mapWithKeys(fn ($p) => [Carbon::parse($p)->toDateString() => Carbon::parse($p)->format('M Y')])->all();
    }

    public function getSubheading(): ?string
    {
        $period = $this->getTableFilterState('period')['value'] ?? array_key_first($this->periods());
        if (! $period) {
            return __('এখনো কোনো মাসের ছায়া বিল হয়নি। প্রতি মাসের ১ তারিখ সকাল ৭:১৫-এ নিজে হয়, বা উপরের বোতাম দিয়ে এই মাসেরটা বানান।');
        }
        $s = DB::table('desk_shadow_bills')->where('company_id', Filament::getTenant()->getKey())->where('period_start', $period)
            ->selectRaw("count(*) FILTER (WHERE desk_reason = 'billed') AS billed, coalesce(sum(desk_amount), 0) AS desk,
                coalesce(sum(billing_amount), 0) AS billing, count(*) FILTER (WHERE result = 'match') AS ok,
                count(*) FILTER (WHERE result = 'pending') AS pending, count(*) AS n, min(created_at) AS made")->first();
        $done = $s->n - $s->pending;

        return __(':m: Desk :b জনকে :d টাকা, বিলিং :bt টাকা। মিল :ok / :done (:pc%), বিলিং-এর বিল আসা বাকি :p। বানানো :t।', [
            'm' => Carbon::parse($period)->format('M Y'), 'b' => number_format($s->billed), 'd' => number_format((float) $s->desk),
            'bt' => number_format((float) $s->billing), 'ok' => number_format($s->ok), 'done' => number_format($done),
            'pc' => $done ? round(100 * $s->ok / $done, 2) : '—', 'p' => number_format($s->pending),
            't' => $s->made ? Carbon::parse($s->made)->timezone('Asia/Dhaka')->format('d M, g:i A') : '—',
        ]).' '.__('শুধু Desk-এর হিসাব, খাতায় বা বিলিং-এ কিছু লেখা হয় না।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('make')->label(__('এই মাসের ছায়া বিল বানান'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->schema([Toggle::make('replace')->label(__('আগে বানানো থাকলে মুছে আবার বানান (এখনকার কাস্টমার দিয়ে)'))])
                ->modalDescription(__('Desk-এর কাস্টমার কপি থেকে এই মাসের বিল হিসাব হবে আর বিলিং-এর বিলের সাথে মেলানো হবে। বিলিং-এ বা খাতায় কিছু লেখা হয় না।'))
                ->action(function (array $data) {
                    try {
                        Engine::deskShadowBills(Filament::getTenant()->getKey(), now('Asia/Dhaka')->format('Y-m'), (bool) ($data['replace'] ?? false));
                        Notification::make()->success()->title(__('শুরু হয়েছে'))->body(__('এক মিনিট পর পেজটা আবার খুলুন।'))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(DeskShadowBill::query()->with('customer')->where('company_id', $company->getKey()))
            ->defaultSort('id')
            ->deferFilters(false)
            ->paginated([50, 100, 500])
            ->columns([
                TextColumn::make('customer.customer_code')->label('ID')
                    ->url(fn (DeskShadowBill $r) => ($b = BillingCustomer::where('company_id', $r->company_id)
                        ->where('header_id', $r->customer?->header_id)->first())
                        ? \App\Filament\App\Resources\Customers\CustomerResource::getUrl('view', ['record' => $b]) : null),
                TextColumn::make('customer.name')->label(__('নাম'))->limit(24),
                TextColumn::make('customer_status')->label('Status'),
                TextColumn::make('desk_amount')->label(__('Desk-এর বিল'))->numeric(0)
                    ->description(fn (DeskShadowBill $r) => __(DeskShadowBill::REASONS[$r->desk_reason] ?? (string) $r->desk_reason)),
                TextColumn::make('billing_amount')->label(__('বিলিং-এর বিল'))->numeric(0)->placeholder('—')
                    ->description(fn (DeskShadowBill $r) => $r->billing_package),
                TextColumn::make('result')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskShadowBill::RESULTS[$state][0] ?? $state))
                    ->color(fn ($state) => DeskShadowBill::RESULTS[$state][1] ?? 'gray')
                    ->description(fn (DeskShadowBill $r) => $r->hint ? __(DeskShadowBill::HINTS[$r->hint] ?? $r->hint) : null),
                TextColumn::make('package_name')->label(__('প্যাকেজ (বানানোর সময়)'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')->label(__('মাস'))->attribute('period_start')->options(fn () => $this->periods())
                    ->default(array_key_first($this->periods())),
                SelectFilter::make('result')->label(__('ফল'))->default('mismatch')
                    ->options(['mismatch' => __('অমিল (সব)')] + collect(DeskShadowBill::RESULTS)->map(fn ($r) => __($r[0]))->all())
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'mismatch' => $query->whereNotIn('result', ['match', 'pending']),
                        default => $query->where('result', $data['value']),
                    }),
            ]);
    }
}
