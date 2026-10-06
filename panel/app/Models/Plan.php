<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['name', 'price_monthly', 'max_agents', 'max_whatsapp_numbers', 'max_bot_replies',
        'trial_days', 'description', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
