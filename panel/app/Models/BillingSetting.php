<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The company's billing rules (plan 2.2), one row per company; every change is kept in billing_settings_log. */
class BillingSetting extends Model
{
    protected $guarded = [];

    /** Shown on the বিলিং নিয়ম page, in this order; the defaults are how the billing software works today. */
    public const DEFAULTS = [
        'bill_generation' => 'month_start', 'waive_off_month' => true, 'desk_auto_approve' => true,
        'expire_on_payment' => true, 'expire_full_percent' => 85, 'expire_max_days' => 28, 'expire_keep_if_early' => true,
        'auto_line' => true, 'disable_when' => 'next_day', 'disable_after_days' => 1, 'disable_hour' => 11, 'disable_min_due' => 0,
        'online_expire' => true, 'mikrotik_retries' => 3, 'enable_min_percent' => 90,
        'partial_redisable_percent' => 40, 'partial_redisable_days' => 3, 'history_months' => 12, 'activity_log_months' => null,
        'match_exclude_codes' => [], 'line_control' => 'off', 'line_test_codes' => [],
    ];

    public const MODES = ['ispdigital' => 'বিলিং সফটওয়্যার মূল (এখন)', 'shadow' => 'ছায়া: Desk পাশাপাশি হিসাব করে', 'desk' => 'Desk মূল'];

    protected function casts(): array
    {
        return ['match_exclude_codes' => 'array', 'line_test_codes' => 'array', 'waive_off_month' => 'boolean', 'desk_auto_approve' => 'boolean', 'expire_on_payment' => 'boolean',
            'expire_keep_if_early' => 'boolean', 'auto_line' => 'boolean', 'online_expire' => 'boolean'];
    }

    public static function forCompany(Company $company): self
    {
        return static::firstOrNew(['company_id' => $company->id], ['billing_mode' => 'ispdigital', ...self::DEFAULTS]);
    }
}
