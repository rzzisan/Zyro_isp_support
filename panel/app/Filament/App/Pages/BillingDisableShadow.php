<?php

namespace App\Filament\App\Pages;

use App\Models\BillingCustomer;
use App\Models\DeskDisableShadow;
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
 * বন্ধের তালিকা মিল (billing phase 2): every day at the disable hour the Desk lists whose line it would turn off for an
 * unpaid bill, and compares it with whom billing actually turned off (its enable/disable log, copied every night).
 * Shadow only: the Desk turns nothing off. Owner only.
 */
class BillingDisableShadow extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $slug = 'billing-disable-shadow';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('বন্ধের তালিকা মিল: Desk বনাম বিলিং');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && auth()->user()?->roleIn($company) === 'owner';
    }

    /** The days with a list, newest first. */
    public function days(): array
    {
        return DB::table('desk_disable_shadow')->where('company_id', Filament::getTenant()->getKey())
            ->whereIn('decision', ['disable', 'keep'])->select('day')->distinct()->orderByDesc('day')->limit(62)->pluck('day')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => Carbon::parse($d)->format('d M Y, D')])->all();
    }

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant()->getKey();
        $day = $this->getTableFilterState('day')['value'] ?? array_key_first($this->days());
        $hour = (int) (DB::table('billing_settings')->where('company_id', $company)->value('disable_hour') ?? 11);
        if (! $day) {
            return __('এখনো কোনো দিনের তালিকা হয়নি। প্রতিদিন :h-এ নিজে হয়, বা উপরের বোতাম দিয়ে আজকেরটা বানান।', ['h' => sprintf('%02d:00', $hour)]);
        }
        $s = DB::table('desk_disable_shadow')->where('company_id', $company)->where('day', $day)
            ->selectRaw("count(*) FILTER (WHERE decision = 'disable') AS dis, count(*) FILTER (WHERE decision = 'keep') AS keep,
                count(*) FILTER (WHERE billing_disabled_at IS NOT NULL OR decision = 'none') AS billing,
                count(*) FILTER (WHERE decision = 'disable' AND result = 'match') AS ok,
                count(*) FILTER (WHERE result = 'desk_only') AS desk_only, count(*) FILTER (WHERE result = 'billing_only') AS billing_only,
                count(*) FILTER (WHERE result = 'pending') AS pending, min(made_at) AS made")->first();
        $done = $s->dis + $s->billing_only - $s->pending;
        $run = DB::table('desk_billing_runs')->where('company_id', $company)->where('kind', 'disable_shadow')->where('status', 'done')
            ->orderByDesc('id')->first();
        $stats = json_decode($run->stats ?? '{}', true) ?: [];
        $halt = ($stats['day'] ?? null) === $day && ! empty($stats['would_halt'])
            ? ' '.__('সতর্কতা: সংখ্যাটা গত ৭ দিনের গড়ের (:a) ৩ গুণের বেশি, আসল কাজ হলে থেমে Owner-কে জানাত।', ['a' => $stats['avg_7_days']])
            : '';

        return __(':d: Desk :x জনের লাইন বন্ধ করত, :k জন বকেয়া থাকলেও চালু থাকত। বিলিং বন্ধ করেছে :b জন। মিল :ok / :done (:pc%), শুধু Desk :do, শুধু বিলিং :bo, লগ আসা বাকি :p। তালিকা :t।', [
            'd' => Carbon::parse($day)->format('d M Y'), 'x' => number_format($s->dis), 'k' => number_format($s->keep),
            'b' => number_format($s->billing), 'ok' => number_format($s->ok), 'done' => number_format(max($done, 0)),
            'pc' => $done > 0 ? round(100 * $s->ok / $done, 2) : '—', 'do' => number_format($s->desk_only),
            'bo' => number_format($s->billing_only), 'p' => number_format($s->pending),
            't' => $s->made ? Carbon::parse($s->made)->timezone('Asia/Dhaka')->format('g:i A') : '—',
        ]).$halt.' '.__('শুধু Desk-এর হিসাব, কারো লাইন বন্ধ হয় না, বিলিং-এ কিছু লেখা হয় না।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('make')->label(__('আজকের তালিকা বানান'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->schema([Toggle::make('replace')->label(__('আগে বানানো থাকলে মুছে আবার বানান (এখনকার হিসাব দিয়ে)'))])
                ->modalDescription(__('Desk-এর খাতা আর নিয়ম দিয়ে আজ কার লাইন বন্ধ হত, তার তালিকা হবে আর বিলিং-এর বন্ধের সাথে মেলানো হবে। কারো লাইন বন্ধ হয় না।'))
                ->action(function (array $data) {
                    try {
                        Engine::deskDisableList(Filament::getTenant()->getKey(), now('Asia/Dhaka')->toDateString(), (bool) ($data['replace'] ?? false));
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
        $label = fn (array $map, $state) => __($map[$state][0] ?? (string) $state);

        return $table
            ->query(DeskDisableShadow::query()->with('customer')->where('company_id', $company->getKey()))
            ->defaultSort('id')
            ->deferFilters(false)
            ->paginated([50, 100, 500])
            ->columns([
                TextColumn::make('customer.customer_code')->label('ID')
                    ->url(fn (DeskDisableShadow $r) => ($b = BillingCustomer::where('company_id', $r->company_id)
                        ->where('header_id', $r->customer?->header_id)->first())
                        ? \App\Filament\App\Resources\Customers\CustomerResource::getUrl('view', ['record' => $b]) : null),
                TextColumn::make('customer.name')->label(__('নাম'))->limit(24),
                TextColumn::make('decision')->label(__('Desk'))->badge()
                    ->formatStateUsing(fn ($state) => $label(DeskDisableShadow::DECISIONS, $state))
                    ->color(fn ($state) => DeskDisableShadow::DECISIONS[$state][1] ?? 'gray')
                    ->description(fn (DeskDisableShadow $r) => $r->reason ? __(DeskDisableShadow::REASONS[$r->reason] ?? $r->reason) : null),
                TextColumn::make('due')->label(__('বকেয়া (শেষ দিন পার)'))->numeric(0)->placeholder('—')
                    ->description(fn (DeskDisableShadow $r) => $r->deadline
                        ? __('Expire :e, শেষ দিন :d', ['e' => $r->expire_on?->format('d M'), 'd' => $r->deadline->format('d M')]) : null),
                TextColumn::make('billing_disabled_at')->label(__('বিলিং বন্ধ করেছে'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->timezone('Asia/Dhaka')->format('d M, g:i A') : null)
                    ->description(fn (DeskDisableShadow $r) => $r->billing_disabled_by),
                TextColumn::make('result')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn ($state) => $label(DeskDisableShadow::RESULTS, $state))
                    ->color(fn ($state) => DeskDisableShadow::RESULTS[$state][1] ?? 'gray')
                    ->description(fn (DeskDisableShadow $r) => $r->hint && $r->hint !== $r->reason
                        ? __(DeskDisableShadow::HINTS[$r->hint] ?? DeskDisableShadow::REASONS[$r->hint] ?? $r->hint) : null),
                TextColumn::make('balance')->label(__('মোট বকেয়া'))->numeric(0)->placeholder('—')
                    ->description(fn (DeskDisableShadow $r) => $r->balance_from === 'billing' ? __('বিলিং-এর হিসাব থেকে') : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('monthly_bill')->label(__('মাসিক বিল'))->numeric(0)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('extended_until')->label(__('সময় বাড়ানো'))->date('d M Y')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('day')->label(__('দিন'))->attribute('day')->options(fn () => $this->days())
                    ->default(array_key_first($this->days())),
                SelectFilter::make('decision')->label(__('Desk'))
                    ->options(collect(DeskDisableShadow::DECISIONS)->map(fn ($r) => __($r[0]))->all()),
                SelectFilter::make('result')->label(__('ফল'))->default('mismatch')
                    ->options(['mismatch' => __('অমিল (সব)')] + collect(DeskDisableShadow::RESULTS)->map(fn ($r) => __($r[0]))->all())
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'mismatch' => $query->whereIn('result', ['desk_only', 'billing_only']),
                        default => $query->where('result', $data['value']),
                    }),
            ]);
    }
}
