<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\DeskExtension;
use App\Support\Extensions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
 * সময় বাড়ানো (billing phase 4a, plan 2.2 #11): every extension given, who gave it, until when and why. The owner and
 * members with the "বিলের সময় বাড়ানো" permission.
 */
class BillingExtensions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'billing-extensions';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.expire-dates';

    public function getTitle(): string|Htmlable
    {
        return __('বিল দেওয়ার সময় বাড়ানো');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && Extensions::canExtend(auth()->user(), $company);
    }

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant();
        $s = BillingSetting::forCompany($company);
        $today = Extensions::today()->toDateString();
        $n = DB::table('desk_extensions')->where('company_id', $company->getKey())->where('status', 'active')
            ->selectRaw('count(*) AS n, count(*) FILTER (WHERE until = ?) AS last_day', [$today])->first();
        $month = DB::table('desk_extensions')->where('company_id', $company->getKey())
            ->where('given_on', '>=', Extensions::today()->startOfMonth()->toDateString())->where('status', '<>', 'canceled')
            ->selectRaw("count(*) AS n, count(*) FILTER (WHERE status = 'paid') AS paid")->first();

        return __('এখন :n জনের সময় বাড়ানো আছে (আজ শেষ দিন :l জনের)। এই মাসে :m বার সময় দেওয়া হয়েছে, তাদের :p জন বিল দিয়েছেন।', [
            'n' => number_format($n->n), 'l' => number_format($n->last_day), 'm' => number_format($month->n), 'p' => number_format($month->paid),
        ]).' '.__('বাড়ানো তারিখ পর্যন্ত বকেয়া থাকলেও Desk লাইন বন্ধ করবে না; তারিখ পার হলে বা পুরো বিল দিলে নিজে থেকে উঠে যায়। নিয়ম: একবারে সর্বোচ্চ :d দিন, মাসে :k বার। বিলিং সফটওয়্যারে কিছু লেখা হয় না।', [
            'd' => $s->extend_max_days ?? 10, 'k' => $s->extend_per_month ?? 1,
        ]);
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(DeskExtension::query()->with(['customer', 'user'])->where('company_id', $company->getKey()))
            ->defaultSort('id', 'desc')
            ->deferFilters(false)
            ->paginated([50, 100, 500])
            ->columns([
                TextColumn::make('customer_code')->label('ID')->searchable()
                    ->url(fn (DeskExtension $r) => ($b = BillingCustomer::where('company_id', $r->company_id)
                        ->where('header_id', $r->customer?->header_id)->first()) ? CustomerResource::getUrl('view', ['record' => $b]) : null)
                    ->description(fn (DeskExtension $r) => $r->customer?->name),
                TextColumn::make('until')->label(__('যে তারিখ পর্যন্ত'))->date('d M Y')->sortable()
                    ->description(fn (DeskExtension $r) => __(':n দিন', ['n' => $r->days]).($r->over_limit ? ' · '.__('নিয়মের বাইরে') : '')),
                TextColumn::make('reason')->label(__('কারণ'))->wrap()->limit(80)
                    ->description(fn (DeskExtension $r) => $r->line_action ? __(DeskExtension::LINE[$r->line_action] ?? $r->line_action) : null),
                TextColumn::make('due_at_start')->label(__('তখন বকেয়া'))->numeric(0)->placeholder('—'),
                TextColumn::make('created_at')->label(__('কে, কখন'))
                    ->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M, g:i A'))
                    ->description(fn (DeskExtension $r) => ($r->user?->name ?? __(DeskExtension::KINDS[$r->kind] ?? $r->kind))
                        .($r->kind === 'bulk' ? ' · '.__('একসাথে অনেকজন') : '')),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => __(DeskExtension::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => DeskExtension::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (DeskExtension $r) => $r->ended_at
                        ? $r->ended_at->timezone('Asia/Dhaka')->format('d M').($r->end_note ? ': '.$r->end_note : '') : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('অবস্থা'))->default('active')
                    ->options(collect(DeskExtension::STATUSES)->map(fn ($s) => __($s[0]))->all()),
                SelectFilter::make('kind')->label(__('কীভাবে'))->options(collect(DeskExtension::KINDS)->map(fn ($s) => __($s))->all()),
            ])
            ->recordActions([
                Action::make('cancel')->label(__('বাতিল'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (DeskExtension $r) => $r->status === 'active')
                    ->schema([Textarea::make('note')->label(__('কেন বাতিল'))->required()->rows(2)->maxLength(500)])
                    ->modalDescription(__('বাড়ানো তারিখ উঠে যাবে, বকেয়া থাকলে পরের বন্ধের তালিকায় নিয়ম মেনে আসবে।'))
                    ->action(function (DeskExtension $r, array $data) {
                        Extensions::cancel($r, (string) $data['note'], auth()->user());
                        Notification::make()->success()->title(__('বাতিল হয়েছে'))->send();
                    }),
            ]);
    }
}
