<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\TicketActions;
use App\Models\BillingCustomer;
use App\Models\BillingOption;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Online client monitoring (like the billing software's ClientMonitoring page), from our MikroTik copy:
 * every customer whose line is on (not disabled, any status: Active/Free/Personal — same set the billing page counts)
 * with online/offline, IP, MAC, uptime and when last seen; re-check, traffic and ping per client.
 */
class Monitoring extends Page implements HasTable
{
    use \App\Filament\App\Concerns\QuickAccessible;

    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function getNavigationLabel(): string
    {
        return __('অনলাইন মনিটরিং');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('অনলাইন ক্লায়েন্ট মনিটরিং');
    }

    protected static ?string $slug = 'monitoring';

    protected static string|\UnitEnum|null $navigationGroup = 'নেটওয়ার্ক';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.app.monitoring';

    #[Url(as: 'server')]
    public string $server = '';

    /** true while the background customer sync started by opening this page is running */
    public bool $refreshing = false;

    public function mount(): void
    {
        // line on/off changes during the day: refresh the customer list when someone opens this page (at most every 10 min)
        $this->refreshing = (bool) (Engine::refreshCustomers(Filament::getTenant()->getKey())['running'] ?? false);
    }

    public function checkRefresh(): void
    {
        if (! \Illuminate\Support\Facades\DB::table('billing_customer_syncs')->where('company_id', Filament::getTenant()->getKey())
            ->whereNull('finished_at')->where('started_at', '>', now()->subMinutes(5))->exists()) {
            $this->refreshing = false;
            $this->resetTable();
        }
    }

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('monitoring') && (MikrotikRouter::where('company_id', Filament::getTenant()?->getKey() ?? 0)->exists());
    }

    public function getSubheading(): ?string
    {
        $last = PppSession::where('company_id', Filament::getTenant()->getKey())->max('seen_at');

        return __('MikroTik থেকে প্রতি ২ মিনিটে আপডেট হয়').($this->refreshing ? __(' · বিলিং থেকে কাস্টমার তালিকা আপডেট হচ্ছে…') : '').($last ? __(' · শেষ আপডেট ').Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('g:i:s A') : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label(__('এখনই Sync'))->icon(Heroicon::OutlinedArrowPath)
                ->action(function () {
                    try {
                        $rows = Engine::syncOnline(Filament::getTenant()->getKey());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Sync হয়নি'))->body($e->getMessage())->send();

                        return;
                    }
                    $rows = array_is_list($rows) ? $rows : [];
                    $failed = collect($rows)->where('ok', false);
                    Notification::make()->{$failed->isEmpty() ? 'success' : 'warning'}()
                        ->title($failed->isEmpty() ? __('MikroTik থেকে আপডেট হয়েছে') : __('কিছু রাউটারে সংযোগ হয়নি'))
                        ->body(collect($rows)->map(fn ($r) => $r['ok'] ? $r['router'].': '.__(':n অনলাইন', ['n' => $r['online']]) : $r['router'].': '.__('সংযোগ হয়নি'))->join(' · '))
                        ->send();
                    $this->resetTable();
                }),
        ];
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
            ->whereNull('gone_at')->where('is_left', false)->where('billing_customers.disabled', false)
            ->when($this->server !== '', fn ($q) => $q->where('server', $this->server))
            ->leftJoin('ppp_sessions as s', fn ($j) => $j->on('s.company_id', '=', 'billing_customers.company_id')
                ->on('s.username', '=', 'billing_customers.username'))
            ->select('billing_customers.*', 's.address as s_address', 's.caller_id as s_mac', 's.uptime as s_uptime', 's.seen_at as s_seen', 's.profile as s_profile')
            // on a block profile (billing disabled the line) the customer is connected but has no internet: blocked, not online
            ->selectRaw('(s.seen_at IS NOT NULL AND s.seen_at > ? AND NOT s.blocked) AS s_online', [$fresh])
            ->selectRaw('(s.seen_at IS NOT NULL AND s.seen_at > ? AND s.blocked) AS s_blocked', [$fresh]);
    }

    /** Status filter: online (with internet) | offline | blocked (connected on a block profile). Joined ppp_sessions as s. */
    private static function whereLine(Builder $q, string $state): void
    {
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);
        match ($state) {
            'online' => $q->where('s.seen_at', '>', $fresh)->where('s.blocked', false),
            'blocked' => $q->where('s.seen_at', '>', $fresh)->where('s.blocked', true),
            'offline' => $q->where(fn ($w) => $w->whereNull('s.seen_at')->orWhere('s.seen_at', '<=', $fresh)),
            default => null,
        };
    }

    /** [server => [total, online]] plus '' for all servers, counting only customers that match the table's filters and search. */
    public function counts(): array
    {
        $fresh = now()->subMinutes(PppSession::FRESH_MINUTES);
        $q = BillingCustomer::query()->where('billing_customers.company_id', Filament::getTenant()->getKey())
            ->whereNull('gone_at')->where('is_left', false)->where('billing_customers.disabled', false)
            ->leftJoin('ppp_sessions as s', fn ($j) => $j->on('s.company_id', '=', 'billing_customers.company_id')
                ->on('s.username', '=', 'billing_customers.username'));
        $this->applyCardFilters($q);
        $rows = $q->selectRaw('server, count(*) AS total, count(*) FILTER (WHERE s.seen_at > ? AND NOT s.blocked) AS online,
                count(*) FILTER (WHERE s.seen_at > ? AND s.blocked) AS blocked', [$fresh, $fresh])
            ->groupBy('server')->orderByDesc('total')->get();
        $out = ['' => ['total' => (int) $rows->sum('total'), 'online' => (int) $rows->sum('online'), 'blocked' => (int) $rows->sum('blocked')]];
        foreach ($rows as $r) {
            if ($r->server && $r->server !== 'Not Found') {
                $out[$r->server] = ['total' => (int) $r->total, 'online' => (int) $r->online, 'blocked' => (int) $r->blocked];
            }
        }
        // keep the chosen server's card even when the filters leave it empty
        if ($this->server !== '' && ! isset($out[$this->server])) {
            $out[$this->server] = ['total' => 0, 'online' => 0, 'blocked' => 0];
        }

        return $out;
    }

    /** The table's filters and search (everything except the server tab) applied to the server-card counts. */
    private function applyCardFilters(Builder $q): void
    {
        $f = $this->tableFilters ?? [];
        static::whereLine($q, (string) ($f['online']['value'] ?? ''));
        foreach (['zone', 'subzone', 'box', 'connection_type'] as $col) {
            if (($v = $f[$col]['value'] ?? null) !== null && $v !== '') {
                $q->where("billing_customers.{$col}", $v);
            }
        }
        $search = trim((string) ($this->tableSearch ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w
                ->whereRaw("ltrim(billing_customers.customer_id, '0') = ltrim(?, '0')", [$search])
                ->orWhere('billing_customers.name', 'ilike', "%{$search}%")
                ->orWhere('billing_customers.username', 'ilike', "%{$search}%")
                ->orWhere('billing_customers.mobile', 'like', '%'.preg_replace('/\D/', '', $search).'%'));
        }
    }

    /** True when any table filter or search is set (the cards then show filtered counts). */
    public function cardsFiltered(): bool
    {
        foreach ($this->tableFilters ?? [] as $data) {
            if (($data['value'] ?? null) !== null && ($data['value'] ?? null) !== '') {
                return true;
            }
        }

        return trim((string) ($this->tableSearch ?? '')) !== '';
    }

    private static function options(string $column): array
    {
        return BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')->where('is_left', false)
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all();
    }

    /** Options of $column limited by the zone/subzone already chosen in the filters. */
    private function scopedOptions(string $column, array $parents): array
    {
        $q = BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')->where('is_left', false)->whereNotNull($column)
            ->when($this->server !== '', fn ($x) => $x->where('server', $this->server));
        foreach ($parents as $p) {
            if ($v = $this->tableFilters[$p]['value'] ?? null) {
                $q->where($p, $v);
            }
        }

        $used = $q->distinct()->orderBy($column)->pluck($column, $column)->all();
        // plus the billing list kept in the desk (বিলিং কনফিগারেশন), narrowed the same way
        $zone = $this->tableFilters['zone']['value'] ?? null;
        $subzone = $this->tableFilters['subzone']['value'] ?? null;
        $where = $column === 'subzone' ? array_filter(['parent_name' => $zone])
            : array_filter(['zone_name' => $zone, 'parent_name' => $subzone]);

        return BillingOption::names(Filament::getTenant()?->getKey() ?? 0, $column === 'subzone' ? 'subzones' : 'boxes', $used, $where);
    }

    private static function listOptions(string $kind, string $column): array
    {
        return BillingOption::names(Filament::getTenant()?->getKey() ?? 0, $kind, static::options($column));
    }

    /** "2 দিন 04:12:09" since the unix time $since; the page's script keeps it ticking every second. */
    private static function offlineFor(int $since): string
    {
        $t = max(0, time() - $since);
        $d = intdiv($t, 86400);

        return ($d ? __(':n দিন', ['n' => $d]).' ' : '').sprintf('%02d:%02d:%02d', intdiv($t % 86400, 3600), intdiv($t % 3600, 60), $t % 60);
    }

    private static function speed(int $bps): string
    {
        return $bps >= 1_000_000 ? round($bps / 1_000_000, 2).' Mbps' : round($bps / 1000).' Kbps';
    }

    public function table(Table $table): Table
    {
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
                TextColumn::make('name')->label(__('নাম'))->description(fn ($record) => $record->mobile)->wrap(),
                TextColumn::make('zone')->label('Zone')->description(fn ($record) => trim($record->subzone.($record->box ? ' · '.$record->box : '')))->wrap(),
                TextColumn::make('server')->label('Server')->toggleable(),
                TextColumn::make('package')->label(__('প্যাকেজ'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('s_online')->label(__('অবস্থা'))->badge()
                    ->state(fn ($record) => $record->s_online ? __('অনলাইন') : ($record->s_blocked ? __('ব্লক') : __('অফলাইন')))
                    ->color(fn ($record) => $record->s_online ? 'success' : ($record->s_blocked ? 'warning' : 'danger'))
                    ->tooltip(fn ($record) => $record->s_blocked ? __('MikroTik-এ ব্লক প্রোফাইলে (:profile): কানেক্ট আছে, ইন্টারনেট নেই', ['profile' => $record->s_profile]) : null),
                TextColumn::make('s_address')->label('IP')->state(fn ($record) => $record->s_online || $record->s_blocked ? $record->s_address : null)->placeholder('—')->copyable(),
                TextColumn::make('s_mac')->label('MAC')->state(fn ($record) => $record->s_online || $record->s_blocked ? $record->s_mac : null)->placeholder('—')->toggleable(),
                TextColumn::make('s_uptime')->label('Duration')->state(fn ($record) => $record->s_online || $record->s_blocked ? $record->s_uptime : null)->placeholder('—'),
                TextColumn::make('s_seen')->label(__('শেষ অনলাইন'))
                    ->state(fn ($record) => ! $record->s_online && ! $record->s_blocked && $record->s_seen ? Carbon::parse($record->s_seen, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A') : null)
                    ->placeholder(fn ($record) => $record->s_online || $record->s_blocked ? __('এখন') : __('জানা নেই')),
                TextColumn::make('s_offline_for')->label(__('অফলাইন কতক্ষণ'))
                    ->state(fn ($record) => ! $record->s_online && ! $record->s_blocked && $record->s_seen ? Carbon::parse($record->s_seen, 'UTC')->getTimestamp() : null)
                    ->formatStateUsing(fn ($state) => new HtmlString('<span class="zm-off-for" data-off-since="'.(int) $state.'">'.static::offlineFor((int) $state).'</span>'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('online')->label(__('অবস্থা'))
                    ->options(['online' => __('অনলাইন'), 'offline' => __('অফলাইন'), 'blocked' => __('ব্লক')])
                    ->query(fn (Builder $query, array $data) => static::whereLine($query, (string) ($data['value'] ?? ''))),
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::listOptions('zones', 'zone'))->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.zone', $v))),
                SelectFilter::make('subzone')->label('Subzone')->options(fn () => $this->scopedOptions('subzone', ['zone']))->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.subzone', $v))),
                SelectFilter::make('box')->label('Box')->options(fn () => $this->scopedOptions('box', ['zone', 'subzone']))->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.box', $v))),
                SelectFilter::make('connection_type')->label('Connection Type')->options(fn () => static::listOptions('connection_types', 'connection_type'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($x, $v) => $x->where('billing_customers.connection_type', $v))),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false) // apply as soon as a value is picked (no "Apply filters" click)
            ->filtersFormColumns(5)
            ->recordActions([
                // one click: the new-ticket form with this customer (and, when offline, the problem) already filled in
                TicketActions::newTicket(fn ($record) => [$record?->customer_id, $record && $record->s_blocked
                    ? __('MikroTik-এ লাইন ব্লক (:profile), ইন্টারনেট নেই', ['profile' => $record->s_profile])
                    : ($record && ! $record->s_online ? __('লাইন অফলাইন').($record->s_seen ? __(', শেষ অনলাইন ').Carbon::parse($record->s_seen, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A') : '')
                    : null)])
                    ->label(__('টিকিট'))->icon(Heroicon::OutlinedTicket)->color('warning'),
                Action::make('recheck')->label(__('রি-চেক'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->action(function ($record) {
                        $this->runOn($record, 'recheck', fn ($r) => ($r['online'] ?? null) === null
                            ? ['warning', __('রাউটার থেকে উত্তর আসেনি')]
                            : (($r['online'] ?? false)
                                ? ['success', __('অনলাইন')." · {$r['uptime']} · {$r['router']}", "IP {$r['address']} · MAC {$r['caller_id']}"]
                                : (($r['blocked'] ?? false)
                                    ? ['warning', __('ব্লক')." ({$r['profile']}) · {$r['router']}", __('কানেক্ট আছে, ইন্টারনেট নেই')." · IP {$r['address']}"]
                                    : ['danger', __('অফলাইন')." ({$r['router']})"])));
                    }),
                Action::make('traffic')->label(__('ট্রাফিক'))->icon(Heroicon::OutlinedArrowsUpDown)->color('gray')
                    ->visible(fn ($record) => (bool) $record->s_online)
                    ->action(fn ($record) => $this->runOn($record, 'traffic', fn ($r) => ['success',
                        __('ডাউনলোড :down · আপলোড :up', ['down' => static::speed($r['download_bps'] ?? 0), 'up' => static::speed($r['upload_bps'] ?? 0)]),
                        __('এই মুহূর্তের গতি')." ({$record->username}, {$r['router']})"])),
                Action::make('ping')->label(__('পিং'))->icon(Heroicon::OutlinedSignal)->color('gray')
                    ->visible(fn ($record) => (bool) $record->s_online)
                    ->action(fn ($record) => $this->runOn($record, 'ping', fn ($r) => [($r['received'] ?? 0) > 0 ? 'success' : 'warning',
                        __('পিং :ip: :got/:sent এসেছে', ['ip' => $record->s_address, 'got' => $r['received'], 'sent' => $r['sent']]).(($r['avg_rtt'] ?? null) ? ' · '.__('গড় :rtt', ['rtt' => $r['avg_rtt']]) : ''),
                        ($r['received'] ?? 0) > 0 ? null : __('রাউটার থেকে পিং গেছে, কাস্টমারের ডিভাইস উত্তর দেয়নি (অনেক রাউটার পিং বন্ধ রাখে)')])),
                Action::make('open')->label(__('বিস্তারিত'))->icon(Heroicon::OutlinedEye)->color('gray')
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
