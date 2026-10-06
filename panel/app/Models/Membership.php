<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's seat in a company (company_user pivot as a model, so Filament can scope it by tenant). */
class Membership extends Model
{
    protected $table = 'company_user';

    protected $fillable = ['company_id', 'user_id', 'role'];

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
