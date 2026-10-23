<?php

namespace App\Services;

use App\Models\BillingCustomer;
use App\Models\NewConnection;
use App\Models\OnuAssignment;
use App\Support\Onu;
use Illuminate\Support\Facades\DB;

/**
 * Saving the ONU form: the assignment, and for purpose "New Connection" the customer's connection in the same step
 * (like the old app). Form fields of the connection are prefixed nc_.
 */
class OnuEntry
{
    public const NC_FIELDS = ['nc_connected_on', 'nc_type', 'nc_name', 'nc_mobile', 'nc_address', 'nc_materials', 'nc_total',
        'nc_deposit', 'nc_order_taker_id', 'nc_money_with_id'];

    public static function save(?OnuAssignment $record, array $data, int $companyId, ?int $userId): OnuAssignment
    {
        return DB::transaction(function () use ($record, $data, $companyId, $userId) {
            $nc = array_intersect_key($data, array_flip(self::NC_FIELDS));
            $staff = array_map('intval', $data['technician_ids'] ?? []);
            $data = array_diff_key($data, $nc, ['technician_ids' => 1, 'nc_due' => 1]);
            [$code, $customerId] = ($data['customer_kind'] ?? 'customer') === Onu::OTHER ? [null, null]
                : Onu::resolveCustomer($companyId, $data['customer_code'] ?? null);
            $data = [...$data, 'customer_code' => $code, 'billing_customer_id' => $customerId, 'company_id' => $companyId];

            $record ??= new OnuAssignment(['user_id' => $userId]);
            $record->fill($data);
            if ($record->purpose === 'New Connection') {
                $record->connection_id = static::saveConnection($record, $nc, $companyId, $userId)->id;
            }
            $record->save();
            $record->technicians()->sync(\App\Models\Technician::where('company_id', $companyId)->whereIn('id', $staff ?: [0])->pluck('id'));

            return $record;
        });
    }

    /** The customer's connection (one per customer ID; for "other" the one already linked), created or updated. */
    private static function saveConnection(OnuAssignment $a, array $nc, int $companyId, ?int $userId): NewConnection
    {
        $c = $a->customer_code
            ? NewConnection::firstOrNew(['company_id' => $companyId, 'customer_code' => $a->customer_code])
            : ($a->connection_id ? NewConnection::find($a->connection_id) : null) ?? new NewConnection(['company_id' => $companyId]);
        $total = round((float) ($nc['nc_total'] ?? 0), 2);
        $deposit = round((float) ($nc['nc_deposit'] ?? 0), 2);
        $c->fill([
            'connected_on' => $nc['nc_connected_on'] ?? $a->assigned_at?->timezone('Asia/Dhaka')->toDateString(),
            'customer_kind' => $a->customer_kind, 'billing_customer_id' => $a->billing_customer_id,
            'name' => $nc['nc_name'], 'mobile' => $nc['nc_mobile'] ?? null, 'address' => $nc['nc_address'] ?? null,
            'connection_type' => $nc['nc_type'] ?? Onu::CONNECTION_TYPES[0], 'materials' => array_values($nc['nc_materials'] ?? []) ?: ['অনু'],
            'order_taker_id' => $nc['nc_order_taker_id'] ?? null, 'money_with_id' => $nc['nc_money_with_id'] ?? null,
        ]);
        // price/deposit as typed; later due payments are already inside the deposit, so only a changed price/deposit resets due
        if (! $c->exists || (float) $c->total !== $total || (float) $c->deposit !== $deposit) {
            $c->fill(['total' => $total, 'deposit' => $deposit, 'due' => round($total - $deposit, 2)]);
        }
        $c->user_id ??= $userId;
        $c->save();

        return $c;
    }

    /**
     * Saving the New Connection form. On create it can also hand out the ONU (onu_* fields), like the old app:
     * purpose "Convert to ONU" for a fiber conversion, else "New Connection"; staff default to the order taker.
     */
    public static function saveConnectionForm(?NewConnection $record, array $data, int $companyId, ?int $userId): NewConnection
    {
        return DB::transaction(function () use ($record, $data, $companyId, $userId) {
            $onu = array_intersect_key($data, array_flip(['onu_brand_id', 'onu_mac', 'onu_technicians']));
            $data = array_diff_key($data, $onu, ['due_display' => 1]);
            [$code, $customerId] = ($data['customer_kind'] ?? 'customer') === Onu::OTHER ? [null, null]
                : Onu::resolveCustomer($companyId, $data['customer_code'] ?? null);
            $total = round((float) ($data['total'] ?? 0), 2);
            $deposit = round((float) ($data['deposit'] ?? 0), 2);
            $record ??= new NewConnection(['company_id' => $companyId, 'user_id' => $userId]);
            $priceChanged = ! $record->exists || (float) $record->total !== $total || (float) $record->deposit !== $deposit;
            $record->fill([...$data, 'customer_code' => $code, 'billing_customer_id' => $customerId, 'company_id' => $companyId,
                'materials' => array_values($data['materials'] ?? [])]);
            if ($priceChanged) {
                $record->due = round($total - $deposit, 2);
            }
            $record->save();

            if (filled($onu['onu_brand_id'] ?? null) && filled($onu['onu_mac'] ?? null)) {
                $a = OnuAssignment::create([
                    'company_id' => $companyId, 'assigned_at' => now(), 'customer_kind' => $record->customer_kind,
                    'customer_code' => $record->customer_code, 'billing_customer_id' => $record->billing_customer_id,
                    'brand_id' => $onu['onu_brand_id'], 'mac_raw' => $onu['onu_mac'],
                    'purpose' => $record->connection_type === 'ফাইবার কনভার্ট' ? 'Convert to ONU' : 'New Connection',
                    'connection_id' => $record->id, 'user_id' => $userId,
                ]);
                $a->technicians()->sync(array_filter($onu['onu_technicians'] ?? []) ?: array_filter([$record->order_taker_id]));
            }

            return $record;
        });
    }

    /** Form state of the linked connection, for editing an assignment. */
    public static function connectionState(OnuAssignment $a): array
    {
        $c = $a->connection;
        if (! $c) {
            $b = $a->customer;

            return ['nc_name' => $b?->name, 'nc_mobile' => $b?->mobile, 'nc_address' => $b?->address];
        }

        return ['nc_connected_on' => $c->connected_on?->toDateString(), 'nc_type' => $c->connection_type, 'nc_name' => $c->name,
            'nc_mobile' => $c->mobile, 'nc_address' => $c->address, 'nc_materials' => $c->materials ?? [], 'nc_total' => $c->total,
            'nc_deposit' => $c->deposit, 'nc_order_taker_id' => $c->order_taker_id, 'nc_money_with_id' => $c->money_with_id];
    }

    /** Customer picker: billing customers by ID / name / mobile / PPPoE, keyed by their ID; a typed ID billing lacks is offered as is. */
    public static function searchCustomers(int $companyId, string $search): array
    {
        $search = trim($search);
        $rows = BillingCustomer::where('company_id', $companyId)->whereNull('gone_at')
            ->matching($search)
            ->orderByRaw("ltrim(customer_id, '0') = ltrim(?, '0') DESC", [$search])->orderBy('is_left')->orderBy('customer_id')->limit(15)
            ->get(['customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on']);
        $options = $rows->mapWithKeys(fn ($c) => [$c->customer_id => BillingCustomer::searchLabel($c)])->all();
        if ($search !== '' && ctype_digit($search) && ! $rows->contains(fn ($c) => ltrim($c->customer_id, '0') === ltrim($search, '0'))) {
            $options[$search] = 'ID '.$search.' ('.__('বিলিং-এর কাস্টমার তালিকায় এখনো নেই').')';
        }

        return $options;
    }

    public static function customerLabel(int $companyId, ?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }
        $c = BillingCustomer::where('company_id', $companyId)->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$code])->first();

        return $c ? BillingCustomer::searchLabel($c) : 'ID '.$code.' ('.__('বিলিং-এ নেই').')';
    }
}
