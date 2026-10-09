<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSetting extends Model
{
    protected $fillable = ['company_id', 'ai_provider', 'ai_model', 'bot_mode', 'live_allowlist',
        'auto_ticket', 'reply_signature', 'extra_prompt', 'customer_prompt', 'technician_prompt', 'voice_provider'];

    protected function casts(): array
    {
        return ['auto_ticket' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
