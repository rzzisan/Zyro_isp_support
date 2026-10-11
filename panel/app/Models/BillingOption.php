<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry of a billing support-page list (employee, department, category, priority), synced by the engine. */
class BillingOption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'synced_at' => 'datetime'];
    }
}
