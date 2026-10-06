<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingConnection extends Model
{
    protected $fillable = ['company_id', 'provider', 'base_url', 'username', 'password',
        'last_checked_at', 'last_check_ok', 'last_check_message'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'last_checked_at' => 'datetime', 'last_check_ok' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
