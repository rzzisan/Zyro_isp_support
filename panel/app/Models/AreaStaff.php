<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who worked tickets in which zone / sub zone / box (engine/ticket_autoassign.py fills it); used to auto-assign. */
class AreaStaff extends Model
{
    protected $table = 'area_staff';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'last_worked_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
