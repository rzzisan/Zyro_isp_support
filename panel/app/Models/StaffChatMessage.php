<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message of টিম চ্যাট (App\Support\StaffChat): text, an image or a voice message, or both text and image. */
class StaffChatMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['media_gone_at' => 'datetime', 'deleted_at' => 'datetime', 'media_seconds' => 'integer'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WalkieGroup::class, 'walkie_group_id');
    }

    public function file(): ?string
    {
        if (! $this->media_path || $this->media_gone_at || str_contains($this->media_path, '..')) {
            return null;
        }
        $path = storage_path('app/staff-chat/'.$this->media_path);

        return is_file($path) ? $path : null;
    }

    /** The sender within 15 minutes, or the company's owner. */
    public function deletableBy(User $user, ?string $role): bool
    {
        if ($this->deleted_at) {
            return false;
        }

        return $role === 'owner' || ($this->sender_id === $user->id && $this->created_at?->gt(now()->subMinutes(15)));
    }
}
