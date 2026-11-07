<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Extra keyword (Bangla or Banglish) added in the super-admin for one KB file (plan §19.3). */
class KbKeywordOverride extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created_at' => 'datetime'];
    }
}
