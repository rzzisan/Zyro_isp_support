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
    protected $fillable = ['name', 'slug', 'status', 'plan_id', 'trial_ends_at', 'subscription_ends_at', 'contact_phone', 'contact_email', 'modules', 'router_port', 'mikrotik_backup_days'];

    /** Optional features a super admin turns on per company (companies.modules). */
    public const MODULES = ['onu' => 'ONU স্টক ও নতুন সংযোগ'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'subscription_ends_at' => 'datetime', 'modules' => 'array'];
    }

    /** Web page of a customer's own router: http://IP:router_port (no port set = plain http://IP). Null for anything that is not an IP. */
    public function routerUrl(?string $ip): ?string
    {
        if (! filter_var((string) $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        return 'http://'.$ip.($this->router_port ? ':'.$this->router_port : '');
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
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

    public function permissionGroups(): HasMany
    {
        return $this->hasMany(PermissionGroup::class);
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

    public function botFaqs(): HasMany
    {
        return $this->hasMany(BotFaq::class);
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
