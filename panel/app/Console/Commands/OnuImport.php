<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ConnectionPayment;
use App\Models\NewConnection;
use App\Models\OnuAssignment;
use App\Models\OnuBrand;
use App\Models\OnuStockEntry;
use App\Models\StockMovement;
use App\Models\StockProduct;
use App\Models\Technician;
use App\Models\User;
use App\Support\Onu;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copies the old ONU app (export of deploy/onu_export.sh) into the Desk, matched by legacy_id, so it can run again
 * until the cutover day: new and changed rows are updated, rows deleted in the old app are deleted here too.
 * Old times are Dhaka time and are saved as UTC. --dry-run does everything inside a transaction and rolls back.
 */
class OnuImport extends Command
{
    protected $signature = 'onu:import {file : JSON lines from deploy/onu_export.sh} {--company= : company slug}
        {--map= : JSON with users / staff / not_ids} {--dry-run : report only, write nothing}';

    protected $description = 'Import the old zisan.me/onu app (ONU stock, new connections, other stock) into a company';

    private int $cid;

    private array $report = [];

    /** old employee id => technician id / user id */
    private array $techOf = [];

    private array $userOf = [];

    private array $names = [];

    /** old full_name => technician id */
    private array $techByName = [];

    public function handle(): int
    {
        $company = Company::where('slug', $this->option('company'))->firstOrFail();
        $this->cid = $company->id;
        $map = json_decode((string) file_get_contents((string) $this->option('map')), true, flags: JSON_THROW_ON_ERROR);
        $data = [];
        foreach (file((string) $this->argument('file'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $data[$row['t']][] = $row['r'];
        }

        DB::beginTransaction();
        try {
            $this->staff($data['employees'] ?? [], $map);
            $brands = $this->brands($data['brands'] ?? [], $data['stock_entries'] ?? []);
            $connections = $this->connections($data['new_connections'] ?? [], $data['due_payments'] ?? [], $map['not_ids'] ?? []);
            $this->assignments($data['onu_assignments'] ?? [], $brands, $connections, $map['not_ids'] ?? []);
            $this->products($data['products'] ?? [], $data['product_stock_logs'] ?? []);
            $this->reconcile($data);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->option('dry-run') ? DB::rollBack() : DB::commit();

        foreach ($this->report as $k => $v) {
            $this->line(str_pad($k, 34).' '.(is_array($v) ? (implode(', ', array_unique($v)) ?: '-') : $v));
        }
        $this->info($this->option('dry-run') ? 'DRY RUN: nothing saved' : 'saved');

        return self::SUCCESS;
    }

    /** Dhaka wall-clock time -> UTC Carbon. */
    private function utc(?string $dhaka): ?Carbon
    {
        return $dhaka ? Carbon::parse($dhaka, 'Asia/Dhaka')->utc() : null;
    }

    private function staff(array $employees, array $map): void
    {
        foreach (User::all(['id', 'email']) as $u) {
            $byEmail[strtolower($u->email)] = $u->id;
        }
        foreach ($map['users'] ?? [] as $old => $email) {
            $uid = $byEmail[strtolower($email)] ?? null;
            if (! $uid || ! DB::table('company_user')->where('company_id', $this->cid)->where('user_id', $uid)->exists()) {
                throw new \RuntimeException("user $email is not a member of this company");
            }
            $this->userOf[(int) $old] = $uid;
        }
        $created = [];
        foreach ($employees as $e) {
            $this->names[(int) $e['id']] = $e['full_name'];
        }
        foreach ($map['staff'] ?? [] as $name => $m) {
            if (! empty($m['new'])) {
                $t = Technician::firstOrCreate(['company_id' => $this->cid, 'name' => $name], [
                    'wa_number' => null, 'active' => false, 'notify_tickets' => false, 'can_switch_lines' => false,
                    'billing_employee_id' => $m['billing_employee_id'] ?? null, 'billing_employee_name' => $m['billing_employee_name'] ?? null,
                    'note' => 'পুরনো ONU অ্যাপ থেকে',
                ]);
                $t->wasRecentlyCreated && $created[] = $name;
            } else {
                $t = Technician::where('company_id', $this->cid)->where('name', $m['technician'])->first()
                    ?? throw new \RuntimeException("technician {$m['technician']} not found");
            }
            $techByName[$name] = $t->id;
        }
        foreach ($this->names as $id => $name) {
            if (isset($techByName[$name])) {
                $this->techOf[$id] = $techByName[$name];
            }
        }
        $this->techByName = $techByName ?? [];
        $this->report['technicians created'] = $created;
    }

    /** old employee id -> technician id; an id used in data but not in the staff map is an error (listed). */
    private function tech($oldId, string $where): ?int
    {
        if ($oldId === null || (int) $oldId === 0) {
            return null;
        }
        if (! isset($this->techOf[(int) $oldId])) {
            $this->report['UNMAPPED staff ('.$where.')'][] = ($this->names[(int) $oldId] ?? '#'.$oldId);

            return null;
        }

        return $this->techOf[(int) $oldId];
    }

    /** Deletes this company's imported rows whose old row is gone. */
    private function prune(string $model, array $keepLegacy, string $label): void
    {
        $n = $model::where('company_id', $this->cid)->whereNotNull('legacy_id')->whereNotIn('legacy_id', $keepLegacy ?: [0])->delete();
        $this->report["deleted (gone in old) $label"] = $n;
    }

    private function brands(array $brands, array $entries): array
    {
        $byOld = [];
        foreach ($brands as $b) {
            $row = OnuBrand::where('company_id', $this->cid)->where('legacy_id', $b['id'])->first()
                ?? OnuBrand::firstOrNew(['company_id' => $this->cid, 'name' => $b['name']]);
            $row->fill(['name' => $b['name'], 'price' => $b['price'], 'legacy_id' => $b['id']]);
            $row->created_at ??= $this->utc($b['created_at']);
            $row->save();
            $byOld[(int) $b['id']] = $row;
        }
        foreach ($entries as $e) {
            OnuStockEntry::updateOrCreate(['company_id' => $this->cid, 'legacy_id' => $e['id']], [
                'brand_id' => $byOld[(int) $e['brand_id']]->id, 'quantity' => $e['quantity'], 'purchase_date' => $e['purchase_date'],
                'created_at' => $this->utc($e['created_at']),
            ]);
        }
        $this->prune(OnuStockEntry::class, array_column($entries, 'id'), 'stock entries');
        $this->report['brands'] = count($brands);
        $this->report['stock entries'] = count($entries);

        return collect($byOld)->mapWithKeys(fn ($b) => [$b->name => $b->id])->all();
    }

    /** [kind, customer_code, billing_customer_id, legacy_customer_code] for an old customer ID. */
    private function customer(?string $raw, array $notIds): array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || in_array($raw, $notIds, true)) {
            return [Onu::OTHER, null, null, $raw === '' ? null : $raw];
        }
        [$code, $id] = Onu::resolveCustomer($this->cid, $raw);
        if (! $id) {
            $this->report['customer IDs not in billing'][] = $raw;
        }

        return ['customer', $code, $id, null];
    }

    private function connections(array $rows, array $payments, array $notIds): array
    {
        $byOld = [];
        $seen = [];
        foreach ($rows as $r) {
            [$kind, $code, $bcId, $legacyCode] = $this->customer($r['customer_id_code'], $notIds);
            if ($code && isset($seen[$code])) {
                $this->report['DUPLICATE connection customer'][] = $r['customer_id_code'].' = '.$seen[$code];
                [$kind, $code, $bcId, $legacyCode] = [Onu::OTHER, null, null, $r['customer_id_code']];
            }
            $code && $seen[$code] = $r['customer_id_code'];
            $materials = array_values(array_filter(array_map('trim', explode(',', (string) $r['materials_used']))));
            foreach (array_diff($materials, Onu::MATERIALS) as $m) {
                $this->report['unknown materials'][] = $m;
            }
            $c = NewConnection::updateOrCreate(['company_id' => $this->cid, 'legacy_id' => $r['id']], [
                'connected_on' => $r['connection_date'], 'customer_kind' => $kind, 'customer_code' => $code,
                'billing_customer_id' => $bcId, 'legacy_customer_code' => $legacyCode,
                'name' => $r['customer_name'], 'mobile' => $r['mobile_number'], 'address' => $r['address'],
                'connection_type' => $r['connection_type'], 'materials' => $materials,
                'total' => $r['total_price'], 'deposit' => $r['deposit_amount'], 'due' => $r['due_amount'],
                'order_taker_id' => $this->tech($r['order_taker_id'], 'order taker'), 'money_with_id' => $this->tech($r['money_with_id'], 'money with'),
                'created_at' => $this->utc($r['created_at']),
            ]);
            $byOld[(int) $r['id']] = $c;
        }
        foreach ($payments as $p) {
            ConnectionPayment::updateOrCreate(['company_id' => $this->cid, 'legacy_id' => $p['id']], [
                'connection_id' => $byOld[(int) $p['connection_id']]->id, 'paid_on' => $p['payment_date'],
                'paid' => $p['paid_amount'], 'discount' => $p['discount_amount'],
                'collected_by_id' => $this->tech($p['collected_by_id'], 'due collector'), 'created_at' => $this->utc($p['created_at']),
            ]);
        }
        $this->prune(ConnectionPayment::class, array_column($payments, 'id'), 'due payments');
        $this->prune(NewConnection::class, array_column($rows, 'id'), 'connections');
        $this->report['connections'] = count($rows);
        $this->report['due payments'] = count($payments);

        // the old app links an ONU to a connection by the typed customer ID
        return collect($rows)->mapWithKeys(fn ($r) => [trim((string) $r['customer_id_code']) => $byOld[(int) $r['id']]->id])->all();
    }

    private function assignments(array $rows, array $brands, array $connections, array $notIds): void
    {
        $badMac = 0;
        foreach ($rows as $r) {
            [$kind, $code, $bcId, $legacyCode] = $this->customer($r['customer_id'], $notIds);
            $brandId = $brands[$r['brand_name']] ?? throw new \RuntimeException("assignment {$r['id']}: brand {$r['brand_name']} not found");
            $userId = $r['created_by'] !== null ? ($this->userOf[(int) $r['created_by']] ?? null) : null;
            if ($r['created_by'] !== null && ! $userId) {
                $this->report['UNMAPPED entry user'][] = $this->names[(int) $r['created_by']] ?? '#'.$r['created_by'];
            }
            $a = OnuAssignment::updateOrCreate(['company_id' => $this->cid, 'legacy_id' => $r['id']], [
                'assigned_at' => $this->utc($r['assignment_date']), 'customer_kind' => $kind, 'customer_code' => $code,
                'billing_customer_id' => $bcId, 'legacy_customer_code' => $legacyCode, 'brand_id' => $brandId,
                'mac_raw' => trim((string) $r['mac_address']), 'purpose' => $r['purpose'],
                'connection_id' => $connections[trim((string) $r['customer_id'])] ?? null,
                'user_id' => $userId, 'created_at' => $this->utc($r['created_at']),
            ]);
            Onu::validMac($a->mac_norm) || $badMac++;
            $ids = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $r['assigned_to']))) as $name) {
                isset($this->techByName[$name]) ? $ids[] = $this->techByName[$name] : $this->report['UNMAPPED assigned_to'][] = $name;
            }
            $a->technicians()->sync(array_unique($ids));
        }
        $this->prune(OnuAssignment::class, array_column($rows, 'id'), 'assignments');
        $this->report['assignments'] = count($rows);
        $this->report['MACs not 12 hex (kept as typed)'] = $badMac;
    }

    private function products(array $products, array $logs): void
    {
        $byOld = [];
        foreach ($products as $p) {
            $row = StockProduct::where('company_id', $this->cid)->where('legacy_id', $p['id'])->first()
                ?? StockProduct::firstOrNew(['company_id' => $this->cid, 'name' => $p['name']]);
            $row->fill(['name' => $p['name'], 'maker' => $p['company'] ?: null, 'current_stock' => $p['current_stock'], 'legacy_id' => $p['id']]);
            $row->created_at ??= $this->utc($p['created_at']);
            $row->save();
            $byOld[(int) $p['id']] = $row->id;
        }
        foreach ($logs as $l) {
            StockMovement::updateOrCreate(['company_id' => $this->cid, 'legacy_id' => $l['id']], [
                'product_id' => $byOld[(int) $l['product_id']], 'moved_on' => $l['log_date'], 'type' => $l['log_type'],
                'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'total_price' => $l['total_price'],
                'technician_id' => $this->tech($l['employee_id'], 'stock out'),
                'reference' => filled($l['reference_customer_id']) ? trim($l['reference_customer_id']) : null,
                'created_at' => $this->utc($l['created_at']), 'updated_at' => $this->utc($l['created_at']),
            ]);
        }
        $this->prune(StockMovement::class, array_column($logs, 'id'), 'stock movements');
        $this->report['products'] = count($products);
        $this->report['stock movements'] = count($logs);
    }

    /** Old vs new totals that must match exactly. */
    private function reconcile(array $data): void
    {
        $ok = true;
        $check = function (string $what, $old, $new) use (&$ok) {
            $same = (string) $old === (string) $new;
            $ok = $ok && $same;
            $this->report['check '.$what] = ($same ? 'OK ' : 'MISMATCH ').$old.' / '.$new;
        };
        foreach (['brands' => OnuBrand::class, 'stock_entries' => OnuStockEntry::class, 'onu_assignments' => OnuAssignment::class,
            'new_connections' => NewConnection::class, 'due_payments' => ConnectionPayment::class, 'products' => StockProduct::class,
            'product_stock_logs' => StockMovement::class] as $t => $model) {
            $check("rows $t", count($data[$t] ?? []), $model::where('company_id', $this->cid)->whereNotNull('legacy_id')->count());
        }
        $out = collect($data['onu_assignments'] ?? [])->countBy('brand_name');
        $stock = Onu::stock($this->cid);
        foreach ($data['brands'] ?? [] as $b) {
            $in = collect($data['stock_entries'] ?? [])->where('brand_id', $b['id'])->sum('quantity');
            $new = OnuBrand::where('company_id', $this->cid)->where('legacy_id', $b['id'])->value('id');
            $check('stock '.$b['name'], $in - ($out[$b['name']] ?? 0), $stock[$new]['left'] ?? 'x');
        }
        $check('total due', number_format(collect($data['new_connections'] ?? [])->sum(fn ($r) => (float) $r['due_amount']), 2, '.', ''),
            number_format((float) NewConnection::where('company_id', $this->cid)->whereNotNull('legacy_id')->sum('due'), 2, '.', ''));
        foreach ($data['products'] ?? [] as $p) {
            $check('product '.$p['name'], $p['current_stock'], StockProduct::where('company_id', $this->cid)->where('legacy_id', $p['id'])->value('current_stock'));
        }
        $this->report['ALL CHECKS'] = $ok ? 'OK' : 'MISMATCH';
    }
}
