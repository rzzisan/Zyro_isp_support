<?php

namespace App\Models;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A user's seat in a company (company_user pivot as a model, so Filament can scope it by tenant). */
class Membership extends Model
{
    protected $table = 'company_user';

    protected $fillable = ['company_id', 'user_id', 'role', 'billing_username', 'billing_password', 'permissions', 'bill_view_employees', 'wa_signature', 'billing_employee_id', 'location_consent_at', 'on_duty_since', 'duty_day', 'duty_ended_at', 'field_technician'];

    protected $hidden = ['billing_password'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'bill_view_employees' => 'array', 'billing_password' => 'encrypted', 'billing_checked_at' => 'datetime', 'billing_check_ok' => 'boolean', 'wa_signature' => 'boolean', 'location_consent_at' => 'datetime', 'on_duty_since' => 'datetime', 'duty_day' => 'date:Y-m-d', 'duty_ended_at' => 'datetime', 'field_technician' => 'boolean'];
    }

    protected static function booted(): void
    {
        // menus or the billing employee changed: which message pushes this member gets
        static::saved(function (Membership $m) {
            if ($m->wasChanged(['role', 'permissions', 'billing_employee_id', 'field_technician']) || $m->wasRecentlyCreated) {
                \App\Support\FieldWork::refreshChats((int) $m->company_id, (int) $m->user_id);
            }
        });
    }

    /** The signed-in member of the current Desk company. */
    public static function current(): ?self
    {
        $company = Filament::getTenant();

        return $company ? static::where('company_id', $company->getKey())->where('user_id', auth()->id())->first() : null;
    }

    /** Bills and line on/off need the member's own billing login (set by the owner on the team page). */
    public static function mayUseBilling(?User $user = null, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();
        $user ??= auth()->user();
        if (! $company || ! $user) {
            return false;
        }

        return (bool) static::where('company_id', $company->getKey())->where('user_id', $user->getKey())->first()?->hasBillingLogin();
    }

    public function hasBillingLogin(): bool
    {
        return filled($this->billing_username) && filled($this->billing_password);
    }

    public const ROLES = ['owner' => 'Owner', 'admin' => 'Admin', 'agent' => 'Agent'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Permission groups the member is in; their menus add to the member's own (App\Support\Menu::keys). */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(PermissionGroup::class, 'membership_permission_group');
    }

    /** Walkie-talkie groups the member is in (App\Support\Walkie). */
    public function walkieGroups(): BelongsToMany
    {
        return $this->belongsToMany(WalkieGroup::class, 'membership_walkie_group');
    }
}
