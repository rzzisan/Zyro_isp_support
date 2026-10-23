<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSetting extends Model
{
    protected $fillable = ['company_id', 'ai_provider', 'ai_model', 'bot_mode', 'live_allowlist',
        'auto_ticket', 'reply_signature', 'extra_prompt', 'customer_prompt', 'technician_prompt', 'voice_provider',
        'voice_reply', 'voice_reply_voice', 'auto_assign', 'auto_assign_exclude',
        'auto_assign_area_categories', 'auto_assign_category_staff', 'field_job_minutes', 'field_job_targets'];

    protected function casts(): array
    {
        return ['auto_ticket' => 'boolean', 'voice_reply' => 'boolean', 'auto_assign' => 'boolean', 'auto_assign_exclude' => 'array',
            'auto_assign_area_categories' => 'array', 'auto_assign_category_staff' => 'array',
            'field_job_targets' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
