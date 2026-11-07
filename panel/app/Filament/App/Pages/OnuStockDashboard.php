<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\QuickAccessible;
use App\Models\NewConnection;
use App\Models\OnuAssignment;
use App\Models\OnuBrand;
use App\Models\OnuStockEntry;
use App\Models\StockMovement;
use App\Models\StockProduct;
use App\Models\Technician;
use App\Support\Onu;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONU ও স্টক ড্যাশবোর্ড: every stock count in one place — ONU per brand (bought, given, left, value), other items,
 * and for the chosen period: ONUs bought / given (by purpose, by staff), new connections, money with whom, due.
 */
class OnuStockDashboard extends Page
{
    use QuickAccessible;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?string $slug = 'onu-stock-dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'ONU ও স্টক';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.app.onu-stock-dashboard';

    public const TZ = 'Asia/Dhaka';

    public const PERIODS = ['month' => 'এই মাস', 'last_month' => 'গত মাস', '90' => '৯০ দিন', 'all' => 'সব সময়'];

    public string $period = 'month';

    public static function getNavigationLabel(): string
    {
        return __('ONU ও স্টক ড্যাশবোর্ড');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ONU ও স্টক ড্যাশবোর্ড');
    }

    public static function canAccess(): bool
    {
        return Onu::canAccess();
    }

    public function setPeriod(string $p): void
    {
        $this->period = array_key_exists($p, self::PERIODS) ? $p : 'month';
    }

    /** [from, until) as Dhaka dates (null = no limit). */
    public function range(): array
    {
        $now = Carbon::now(self::TZ);

        return match ($this->period) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()],
            '90' => [$now->copy()->startOfDay()->subDays(89), null],
            'all' => [null, null],
            default => [$now->copy()->startOfMonth(), null],
        };
    }

    public function report(): array
    {
        $c = (int) Filament::getTenant()->getKey();
        [$from, $until] = $this->range();
        // timestamps are UTC, dates are Dhaka dates
        $atRange = fn ($q, string $col) => $q->when($from, fn ($q) => $q->where($col, '>=', $from->copy()->utc()))
            ->when($until, fn ($q) => $q->where($col, '<', $until->copy()->utc()));
        $dayRange = fn ($q, string $col) => $q->when($from, fn ($q) => $q->where($col, '>=', $from->toDateString()))
            ->when($until, fn ($q) => $q->where($col, '<', $until->toDateString()));

        // ONU per brand
        $stock = Onu::stock($c);
        $boughtP = $dayRange(OnuStockEntry::where('company_id', $c), 'purchase_date')->groupBy('brand_id')
            ->selectRaw('brand_id, sum(quantity) q')->pluck('q', 'brand_id');
        $givenP = $atRange(OnuAssignment::where('company_id', $c), 'assigned_at')->groupBy('brand_id')
            ->selectRaw('brand_id, count(*) q')->pluck('q', 'brand_id');
        $brands = OnuBrand::where('company_id', $c)->orderBy('name')->get()->map(fn ($b) => [
            'name' => $b->name, 'price' => (float) $b->price,
            'in' => $stock[$b->id]['in'] ?? 0, 'out' => $stock[$b->id]['out'] ?? 0, 'left' => $stock[$b->id]['left'] ?? 0,
            'in_p' => (int) ($boughtP[$b->id] ?? 0), 'out_p' => (int) ($givenP[$b->id] ?? 0),
            'value' => max(0, $stock[$b->id]['left'] ?? 0) * (float) $b->price,
        ])->sortByDesc('left')->values();

        // other items
        $moves = $dayRange(StockMovement::where('company_id', $c), 'moved_on')->groupBy('product_id', 'type')
            ->selectRaw('product_id, type, sum(quantity) q, sum(coalesce(total_price, 0)) t')->get();
        $products = StockProduct::where('company_id', $c)->orderBy('name')->get()->map(fn ($p) => [
            'name' => $p->name, 'stock' => (int) $p->current_stock,
            'in_p' => (int) $moves->where('product_id', $p->id)->where('type', 'in')->sum('q'),
            'out_p' => (int) $moves->where('product_id', $p->id)->where('type', 'out')->sum('q'),
            'spent_p' => (float) $moves->where('product_id', $p->id)->where('type', 'in')->sum('t'),
        ])->sortBy([['stock', 'asc']])->values();

        // ONU given in the period: by purpose and by staff (an ONU fitted by two counts for both)
        $assignP = $atRange(OnuAssignment::where('onu_assignments.company_id', $c), 'assigned_at');
        $purposes = (clone $assignP)->groupBy('purpose')->selectRaw('purpose, count(*) n')->orderByDesc('n')->pluck('n', 'purpose');
        $names = Technician::where('company_id', $c)->pluck('name', 'id');
        $onuByStaff = (clone $assignP)->join('onu_assignment_technician as x', 'x.onu_assignment_id', '=', 'onu_assignments.id')
            ->groupBy('x.technician_id')->selectRaw('x.technician_id, count(*) n')->pluck('n', 'technician_id');
        $itemsByStaff = $dayRange(StockMovement::where('company_id', $c)->where('type', 'out'), 'moved_on')->whereNotNull('technician_id')
            ->groupBy('technician_id')->selectRaw('technician_id, sum(quantity) n')->pluck('n', 'technician_id');

        // new connections in the period, and who holds the money
        $conn = $dayRange(NewConnection::where('company_id', $c), 'connected_on');
        $connTotals = (clone $conn)->selectRaw('count(*) n, coalesce(sum(total), 0) total, coalesce(sum(deposit), 0) deposit, coalesce(sum(due), 0) due')->first();
        $types = (clone $conn)->groupBy('connection_type')->selectRaw('connection_type, count(*) n')->pluck('n', 'connection_type');
        $connByStaff = (clone $conn)->whereNotNull('order_taker_id')->groupBy('order_taker_id')->selectRaw('order_taker_id, count(*) n')->pluck('n', 'order_taker_id');
        $money = (clone $conn)->whereNotNull('money_with_id')->groupBy('money_with_id')
            ->selectRaw('money_with_id, count(*) n, sum(deposit) deposit')->get()->keyBy('money_with_id');
        $paidP = $dayRange(DB::table('connection_payments')->where('company_id', $c), 'paid_on')
            ->selectRaw('count(*) n, coalesce(sum(paid), 0) paid, coalesce(sum(discount), 0) discount')->first();
        $dueAll = NewConnection::where('company_id', $c)->where('due', '>', 0)->selectRaw('count(*) n, coalesce(sum(due), 0) due')->first();
        $dueByMoney = NewConnection::where('company_id', $c)->where('due', '>', 0)->groupBy('money_with_id')
            ->selectRaw('money_with_id, count(*) n, sum(due) due')->get()->keyBy('money_with_id');

        $staff = collect([...$onuByStaff->keys(), ...$itemsByStaff->keys(), ...$connByStaff->keys(), ...$money->keys(), ...$dueByMoney->keys()])
            ->filter()->unique()->map(fn ($id) => [
                'name' => $names[$id] ?? '#'.$id,
                'onu' => (int) ($onuByStaff[$id] ?? 0), 'items' => (int) ($itemsByStaff[$id] ?? 0), 'conn' => (int) ($connByStaff[$id] ?? 0),
                'deposit' => (float) ($money[$id]->deposit ?? 0), 'due' => (float) ($dueByMoney[$id]->due ?? 0),
            ])->sortByDesc(fn ($r) => $r['onu'] + $r['conn'] + $r['items'])->values();

        return [
            'brands' => $brands, 'products' => $products, 'purposes' => $purposes, 'types' => $types, 'staff' => $staff,
            'onu_left' => (int) $brands->sum(fn ($b) => max(0, $b['left'])), 'onu_value' => (float) $brands->sum('value'),
            'onu_in_p' => (int) $brands->sum('in_p'), 'onu_out_p' => (int) $brands->sum('out_p'),
            'low_brands' => $brands->where('left', '<=', 0)->count(),
            'conn' => $connTotals, 'paid' => $paidP, 'due_all' => $dueAll,
            'items_out' => $products->where('stock', '<=', 0)->count(), 'items_total' => $products->count(),
        ];
    }
}
