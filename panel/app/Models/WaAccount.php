<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaAccount extends Model
{
    protected $fillable = ['company_id', 'waba_id', 'phone_number_id', 'display_phone_number', 'verified_name',
        'access_token', 'bot_enabled'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'bot_enabled' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
