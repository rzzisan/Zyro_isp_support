<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's Desk softphone login (Asterisk endpoint "a{id}", written by engine/voice_bot.py). */
class SipAgent extends Model
{
    protected $fillable = ['company_id', 'user_id', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
