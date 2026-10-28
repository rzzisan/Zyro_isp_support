<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customer's Expire Date (billing's BillingLastDate) moved to the day they paid after the line was off
 * (engine/expire_dates.py). Rows are written by the engine; the panel only ticks bulk rows in or out (preview / skipped).
 */
class ExpireDateChange extends Model
{
    protected $guarded = [];

    public const STATUSES = [
        'preview' => ['বাছাই করা', 'info'],
        'skipped' => ['বাদ (হাতে)', 'gray'],
        'queued' => ['অপেক্ষায়', 'warning'],
        'running' => ['চলছে', 'warning'],
        'done' => ['হয়েছে', 'success'],
        'failed' => ['ব্যর্থ', 'danger'],
        'rolled_back' => ['আগের দিনে ফেরত', 'gray'],
    ];

    public const RULES = [
        'pay_day' => 'বিল দেওয়ার দিন',
        'home_plus5' => 'Home: দিন + ৫',
        'home_cap20' => 'Home: সর্বোচ্চ ২০',
        'cap_max' => 'সর্বোচ্চ ২৮',
    ];

    protected function casts(): array
    {
        return ['off_at' => 'datetime', 'on_at' => 'datetime', 'paid_on' => 'date', 'applied_at' => 'datetime',
            'rolled_back_at' => 'datetime', 'is_online' => 'boolean', 'paid' => 'decimal:2', 'payable' => 'decimal:2'];
    }

    /** The tick on the bulk list: in (preview) or out (skipped). Only rows not written yet can change. */
    public function getSelectedAttribute(): bool
    {
        return $this->status === 'preview';
    }

    public function setSelectedAttribute($value): void
    {
        if (in_array($this->status, ['preview', 'skipped'], true)) {
            $this->attributes['status'] = $value ? 'preview' : 'skipped';
        }
    }

    public function editable(): bool
    {
        return $this->kind === 'bulk' && in_array($this->status, ['preview', 'skipped'], true);
    }
}
