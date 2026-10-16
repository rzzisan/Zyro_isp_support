<?php

namespace App\Models;

use App\Support\Onu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One ONU handed out: to which customer (or "other"), which brand and MAC, why, by whom. */
class OnuAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (OnuAssignment $a) {
            $a->mac_norm = Onu::normalizeMac($a->mac_raw);
            if ($a->customer_kind === Onu::OTHER) {
                $a->customer_code = null;
                $a->billing_customer_id = null;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(OnuBrand::class, 'brand_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NewConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'onu_assignment_technician')->orderBy('name');
    }

    public function isOther(): bool
    {
        return $this->customer_kind === Onu::OTHER;
    }

    public function macLabel(): string
    {
        return Onu::displayMac($this->mac_norm, $this->mac_raw);
    }

    /** The OLT ONU with this MAC (live light level), if our OLT sync sees it. */
    public function liveOnu(): ?object
    {
        return $this->mac_norm ? (Onu::liveOnus($this->company_id)[$this->mac_norm] ?? null) : null;
    }
}
