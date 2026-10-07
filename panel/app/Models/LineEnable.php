<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line a technician asked the bot to turn on (written by the engine). */
class LineEnable extends Model
{
    public const UPDATED_AT = null;

    public const RESULTS = ['enabled' => 'চালু করা হয়েছে', 'already_active' => 'আগেই চালু ছিল', 'failed' => 'ব্যর্থ', 'dry_run' => 'টেস্ট মোড'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
