<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ONUs of one brand bought on a day (stock in). */
class OnuStockEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'quantity' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(OnuBrand::class, 'brand_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
