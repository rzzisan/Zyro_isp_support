<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A field team of billing employees; a ticket may hold staff of one team only. */
class StaffTeam extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['members' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
