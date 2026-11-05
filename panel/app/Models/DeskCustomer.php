<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The Desk's own customer row (copied from billing while billing is the master; engine/desk_billing.py). */
class DeskCustomer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['monthly_bill' => 'decimal:2', 'is_vip' => 'boolean', 'is_left' => 'boolean', 'joined_on' => 'date'];
    }
}
