<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's seat in a company (company_user pivot as a model, so Filament can scope it by tenant). */
class Membership extends Model
{
    protected $table = 'company_user';

    protected $fillable = ['company_id', 'user_id', 'role', 'billing_username', 'billing_password', 'permissions'];

    protected $hidden = ['billing_password'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'billing_password' => 'encrypted', 'billing_checked_at' => 'datetime', 'billing_check_ok' => 'boolean'];
    }

    public function hasBillingLogin(): bool
    {
        return filled($this->billing_username) && filled($this->billing_password);
    }

    public const ROLES = ['owner' => 'Owner', 'admin' => 'Admin', 'agent' => 'Agent'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
