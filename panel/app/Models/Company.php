<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = ['name', 'slug', 'status', 'plan_id', 'trial_ends_at', 'subscription_ends_at', 'contact_phone', 'contact_email'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'subscription_ends_at' => 'datetime'];
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Agent seats left under the current plan (null = no plan / unlimited). */
    public function seatsLeft(): ?int
    {
        if (! $this->plan) {
            return null;
        }

        return max(0, $this->plan->max_agents - $this->users()->count());
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function aiKeys(): HasMany
    {
        return $this->hasMany(AiKey::class);
    }

    public function billingConnection(): HasOne
    {
        return $this->hasOne(BillingConnection::class);
    }

    public function botSetting(): HasOne
    {
        return $this->hasOne(BotSetting::class);
    }

    public function waAccounts(): HasMany
    {
        return $this->hasMany(WaAccount::class);
    }

    public function waContacts(): HasMany
    {
        return $this->hasMany(WaContact::class);
    }

    public function billingTickets(): HasMany
    {
        return $this->hasMany(BillingTicket::class);
    }

    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }

    public function lineEnables(): HasMany
    {
        return $this->hasMany(LineEnable::class);
    }

    public function billingCustomers(): HasMany
    {
        return $this->hasMany(BillingCustomer::class);
    }

    public function mikrotikRouters(): HasMany
    {
        return $this->hasMany(MikrotikRouter::class);
    }

    public function olts(): HasMany
    {
        return $this->hasMany(Olt::class);
    }

    public function onus(): HasMany
    {
        return $this->hasMany(Onu::class);
    }
}
