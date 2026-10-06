<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = ['name', 'slug', 'status', 'plan', 'trial_ends_at', 'contact_phone', 'contact_email'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (blank($company->slug)) {
                $base = Str::slug($company->name) ?: 'company';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $company->slug = $slug;
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
