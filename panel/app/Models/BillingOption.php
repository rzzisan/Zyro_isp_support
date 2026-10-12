<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a billing list (support page: employee, department, category, priority; Configuration menu: zone,
 * sub zone, box, package, ...). Kept in the desk; only the "বিলিং কনফিগারেশন" pages' Sync buttons refresh it.
 */
class BillingOption extends Model
{
    protected $guarded = [];

    /** kind => desk label */
    public const KINDS = [
        'zones' => 'Zone',
        'subzones' => 'Sub Zone',
        'boxes' => 'Box',
        'connection_types' => 'Connection Type',
        'client_types' => 'Client Type',
        'protocol_types' => 'Protocol Type',
        'customer_statuses' => 'Customer Status',
        'packages' => 'Package',
        'districts' => 'District',
        'upazilas' => 'Upazila',
        'employees' => 'কর্মী',
        'departments' => 'ডিপার্টমেন্ট',
        'categories' => 'সমস্যার ধরন',
        'priorities' => 'Priority',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'billing_enabled' => 'boolean', 'extra' => 'array', 'synced_at' => 'datetime'];
    }

    /**
     * Names of a list for a dropdown ([name => name]), plus $also (values already used in our data, e.g. an old zone
     * name on a ticket) so filtering by them keeps working. $where narrows the list (e.g. sub zones of a zone).
     */
    public static function names(int $companyId, string $kind, iterable $also = [], array $where = []): array
    {
        $names = static::where('company_id', $companyId)->where('kind', $kind)->where('active', true)
            ->where($where)->pluck('name')->all();
        $out = [];
        foreach ([...$names, ...$also] as $n) {
            if ($n !== null && $n !== '') {
                $out[$n] = $n;
            }
        }
        uksort($out, 'strnatcasecmp');

        return $out;
    }
}
