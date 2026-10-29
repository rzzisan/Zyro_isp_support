<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recording written by the engine (engine/walkie.py): one push-to-talk in a group, or a whole private call. */
class WalkieClip extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'seconds' => 'float'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WalkieGroup::class, 'walkie_group_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(WalkieCall::class, 'walkie_call_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function file(): ?string
    {
        $path = storage_path('app/walkie/'.$this->path);

        return ! str_contains($this->path, '..') && is_file($path) ? $path : null;
    }
}
