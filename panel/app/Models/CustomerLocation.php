<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Where a customer is, learned from the first job there finished with the line online. */
class CustomerLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
