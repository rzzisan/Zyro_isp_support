<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\MikrotikRouter;
use App\Models\PppSession;
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
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Online client monitoring (like the billing software's ClientMonitoring page), from our MikroTik copy:
 * every active customer with online/offline, IP, MAC, uptime and when last seen; re-check, traffic and ping per client.
 */
class Monitoring extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'অনলাইন মনিটরিং';

    protected static ?string $title = 'অনলাইন ক্লায়েন্ট মনিটরিং';

    protected static ?string $slug = 'monitoring';

    protected static ?int $navigationSort = -7;

    protected string $view = 'filament.app.monitoring';

    #[Url(as: 'server')]
    public string $server = '';

    public static function canAccess(): bool
    {
        return MikrotikRouter::where('company_id', Filament::getTenant()?->getKey() ?? 0)->exists();
    }

    public function getSubheading(): ?string
    {
        $last = PppSession::where('company_id', Filament::getTenant()->getKey())->max('seen_at');

        return 'MikroTik থেকে প্রতি ২ মিনিটে আপডেট হয়'.($last ? ' · শেষ আপডেট '.Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('g:i:s A') : '');
    }

    public function setServer(string $server): void
    {
        $this->server = $server;
        $this->resetTable();
    }

    private function base(): Builder
    {
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);

        return BillingCustomer::query()->where('billing_customers.company_id', Filament::getTenant()->getKey())
            ->whereNull('gone_at')->where('billing_customers.status', 'Active')
            ->when($this->server !== '', fn ($q) => $q->where('server', $this->server))
            ->leftJoin('ppp_sessions as s', fn ($j) => $j->on('s.company_id', '=', 'billing_customers.company_id')
                ->on('s.username', '=', 'billing_customers.username'))
            ->select('billing_customers.*', 's.address as s_address', 's.caller_id as s_mac', 's.uptime as s_uptime', 's.seen_at as s_seen')
            ->selectRaw('(s.seen_at IS NOT NULL AND s.seen_at > ?) AS s_online', [$fresh]);
    }

    /** [server => [total, online]] plus '' for all servers. */
    public function counts(): array
    {
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);
        $rows = BillingCustomer::query()->where('billing_customers.company_id', Filament::getTenant()->getKey())
            ->whereNull('gone_at')->where('billing_customers.status', 'Active')
            ->leftJoin('ppp_sessions as s', fn ($j) => $j->on('s.company_id', '=', 'billing_customers.company_id')
                ->on('s.username', '=', 'billing_customers.username'))
            ->selectRaw('server, count(*) AS total, count(*) FILTER (WHERE s.seen_at > ?) AS online', [$fresh])
            ->groupBy('server')->orderByDesc('total')->get();
        $out = ['' => ['total' => (int) $rows->sum('total'), 'online' => (int) $rows->sum('online')]];
        foreach ($rows as $r) {
            if ($r->server && $r->server !== 'Not Found') {
                $out[$r->server] = ['total' => (int) $r->total, 'online' => (int) $r->online];
            }
        }

        return $out;
    }

    private static function options(string $column): array
    {
        return BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all();
    }

    private static function speed(int $bps): string
    {
        return $bps >= 1_000_000 ? round($bps / 1_000_000, 2).' Mbps' : round($bps / 1000).' Kbps';
    }

    public function table(Table $table): Table
    {
        $fresh = fn () => now()->subMinutes(PppSession::FRESH_MINUTES);

        return $table
            ->query(fn () => $this->base())
            ->poll('60s')
            ->defaultSort('customer_id')
            ->paginated([25, 50, 100, 250])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('customer_id')->label('C.Code')->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                        ->whereRaw("ltrim(billing_customers.customer_id, '0') = ltrim(?, '0')", [$search])
                        ->orWhere('billing_customers.name', 'ilike', "%{$search}%")
                        ->orWhere('billing_customers.username', 'ilike', "%{$search}%")
                        ->orWhere('billing_customers.mobile', 'like', '%'.preg_replace('/\D/', '', $search).'%'))),
                TextColumn::make('username')->label('PPPoE ID')->copyable(),
                TextColumn::make('name')->label('নাম')->description(fn ($record) => $record->mobile)->wrap(),
                TextColumn::make('zone')->label('Zone')->description(fn ($record) => trim($record->subzone.($record->box ? ' · '.$record->box : '')))->wrap(),
                TextColumn::make('server')->label('Server')->toggleable(),
                TextColumn::make('package')->label('প্যাকেজ')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('s_online')->label('অবস্থা')->badge()
                    ->state(fn ($record) => $record->s_online ? 'অনলাইন' : 'অফলাইন')
                    ->color(fn (string $state) => $state === 'অনলাইন' ? 'success' : 'danger'),
                TextColumn::make('s_address')->label('IP')->state(fn ($record) => $record->s_online ? $record->s_address : null)->placeholder('—')->copyable(),
                TextColumn::make('s_mac')->label('MAC')->state(fn ($record) => $record->s_online ? $record->s_mac : null)->placeholder('—')->toggleable(),
                TextColumn::make('s_uptime')->label('Duration')->state(fn ($record) => $record->s_online ? $record->s_uptime : null)->placeholder('—'),
                TextColumn::make('s_seen')->label('শেষ অনলাইন')
                    ->state(fn ($record) => ! $record->s_online && $record->s_seen ? Carbon::parse($record->s_seen, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A') : null)
                    ->placeholder(fn ($record) => $record->s_online ? 'এখন' : 'জানা নেই'),
            ])
            ->filters([
                TernaryFilter::make('online')->label('অবস্থা')->trueLabel('অনলাইন')->falseLabel('অফলাইন')
                    ->queries(true: fn (Builder $q) => $q->where('s.seen_at', '>', $fresh()),
                        false: fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('s.seen_at')->orWhere('s.seen_at', '<=', $fresh()))),
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::options('zone'))->searchable()
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.zone', $v))),
                SelectFilter::make('subzone')->label('Subzone')->options(fn () => static::options('subzone'))->searchable()
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.subzone', $v))),
                SelectFilter::make('box')->label('Box')->options(fn () => static::options('box'))->searchable()
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.box', $v))),
                SelectFilter::make('connection_type')->label('Connection Type')->options(fn () => static::options('connection_type'))
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.connection_type', $v))),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->recordActions([
                Action::make('recheck')->label('রি-চেক')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->action(function ($record) {
                        $this->runOn($record, 'recheck', fn ($r) => ($r['online'] ?? null) === null
                            ? ['warning', 'রাউটার থেকে উত্তর আসেনি']
                            : (($r['online'] ?? false)
                                ? ['success', "অনলাইন · {$r['uptime']} · {$r['router']}", "IP {$r['address']} · MAC {$r['caller_id']}"]
                                : ['danger', "অফলাইন ({$r['router']})"]));
                    }),
                Action::make('traffic')->label('ট্রাফিক')->icon(Heroicon::OutlinedArrowsUpDown)->color('gray')
                    ->visible(fn ($record) => (bool) $record->s_online)
                    ->action(fn ($record) => $this->runOn($record, 'traffic', fn ($r) => ['success',
                        'ডাউনলোড '.static::speed($r['download_bps'] ?? 0).' · আপলোড '.static::speed($r['upload_bps'] ?? 0),
                        "এই মুহূর্তের গতি ({$record->username}, {$r['router']})"])),
                Action::make('ping')->label('পিং')->icon(Heroicon::OutlinedSignal)->color('gray')
                    ->visible(fn ($record) => (bool) $record->s_online)
                    ->action(fn ($record) => $this->runOn($record, 'ping', fn ($r) => [($r['received'] ?? 0) > 0 ? 'success' : 'warning',
                        "পিং {$record->s_address}: {$r['received']}/{$r['sent']} এসেছে".(($r['avg_rtt'] ?? null) ? " · গড় {$r['avg_rtt']}" : ''),
                        ($r['received'] ?? 0) > 0 ? null : 'রাউটার থেকে পিং গেছে, কাস্টমারের ডিভাইস উত্তর দেয়নি (অনেক রাউটার পিং বন্ধ রাখে)'])),
                Action::make('open')->label('বিস্তারিত')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->url(fn ($record) => CustomerResource::getUrl('view', ['record' => $record->id])),
            ]);
    }

    private function runOn($record, string $what, \Closure $message): void
    {
        try {
            $r = Engine::monitor((int) $record->company_id, (int) $record->header_id, $what);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        [$type, $title, $body] = array_pad($message($r), 3, null);
        Notification::make()->{$type}()->title($title)->body($body)->send();
    }
}
