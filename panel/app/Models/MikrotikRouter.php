<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikRouter extends Model
{
    protected $fillable = ['company_id', 'host', 'api_port', 'username', 'password', 'billing_server', 'enabled'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'enabled' => 'boolean', 'last_check_ok' => 'boolean', 'last_checked_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Customers whose billing "Server" is this router. */
    public function customerCount(): int
    {
        return $this->billing_server
            ? BillingCustomer::where('company_id', $this->company_id)->whereNull('gone_at')->where('is_left', false)->where('server', $this->billing_server)->count()
            : 0;
    }
}
