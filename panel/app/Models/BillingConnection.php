<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingConnection extends Model
{
    protected $fillable = ['company_id', 'provider', 'base_url', 'username', 'password',
        'last_checked_at', 'last_check_ok', 'last_check_message', 'live_from_billing', 'onu_from_billing', 'tickets_in_billing'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'last_checked_at' => 'datetime', 'last_check_ok' => 'boolean',
            'live_from_billing' => 'boolean', 'onu_from_billing' => 'boolean', 'tickets_in_billing' => 'boolean'];
    }

    /** New tickets open in the Desk (engine/desk_tickets.py) instead of the billing software. */
    public static function deskTickets(int $companyId): bool
    {
        $v = static::where('company_id', $companyId)->value('tickets_in_billing');

        return $v === null ? ! static::where('company_id', $companyId)->exists() : ! $v;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
