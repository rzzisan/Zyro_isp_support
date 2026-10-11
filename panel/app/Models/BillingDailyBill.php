<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bill collected in the billing software (Daily Bill Collection), copied by engine/daily_bill_sync.py. Read-only here. */
class BillingDailyBill extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['collected_at' => 'datetime', 'billing_updated_at' => 'datetime', 'synced_at' => 'datetime',
            'is_approved' => 'boolean', 'is_deleted' => 'boolean', 'monthly_bill' => 'decimal:2',
            'receive_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_amount' => 'decimal:2',
            'balance_due' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function status(): string
    {
        return $this->is_deleted ? 'deleted' : ($this->is_approved ? 'approved' : 'pending');
    }

    /**
     * Rows a member may see: owner/admin everything; an agent the collectors picked on the team page
     * (billing employee ids) plus the bills they entered themselves with their own billing username.
     */
    public static function scopeVisibleTo(Builder $query, ?Membership $m): Builder
    {
        if (! $m) {
            return $query->whereRaw('false');
        }
        if (in_array($m->role, ['owner', 'admin'], true)) {
            return $query;
        }
        $employees = array_map('intval', $m->bill_view_employees ?? []);
        $own = trim((string) $m->billing_username);

        return $query->where(function (Builder $w) use ($employees, $own) {
            $w->whereRaw('false');
            if ($employees) {
                $w->orWhereIn('emp_header_id', $employees);
            }
            if ($own !== '') {
                $w->orWhereRaw('lower(created_by) = lower(?)', [$own]);
            }
        });
    }

    /** [emp_header_id => name] of everyone who received money for this company, for filters and the team page. */
    public static function collectors(int $companyId): array
    {
        return static::where('company_id', $companyId)->whereNotNull('emp_header_id')
            ->selectRaw('emp_header_id, max(received_by) AS name')->groupBy('emp_header_id')->orderBy('name')
            ->pluck('name', 'emp_header_id')->all();
    }
}
