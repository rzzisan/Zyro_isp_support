<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A walkie-talkie channel: its members hear every push-to-talk and may call each other (App\Support\Walkie). */
class WalkieGroup extends Model
{
    protected $fillable = ['company_id', 'name'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'membership_walkie_group');
    }

    /** The LiveKit room of the group. */
    public function room(): string
    {
        return "c{$this->company_id}-g{$this->id}";
    }
}
