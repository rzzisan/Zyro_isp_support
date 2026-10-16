<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ONU model the company stocks, with its selling price. */
class OnuBrand extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stockEntries(): HasMany
    {
        return $this->hasMany(OnuStockEntry::class, 'brand_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OnuAssignment::class, 'brand_id');
    }
}
