<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Other stock items (splitter, patch cord, …): current count, changed only through movements. */
class StockProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['current_stock' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    /** Stock in (bought) or out (handed to a technician); out never goes below zero. */
    public function move(string $type, int $quantity, array $data): StockMovement
    {
        return DB::transaction(function () use ($type, $quantity, $data) {
            $p = static::whereKey($this->id)->lockForUpdate()->first();
            if ($type === 'out' && $p->current_stock < $quantity) {
                throw new \RuntimeException(__('স্টকে যথেষ্ট নেই, আছে ').$p->current_stock);
            }
            $m = $p->movements()->create([...$data, 'company_id' => $p->company_id, 'type' => $type, 'quantity' => $quantity,
                'total_price' => $type === 'in' && isset($data['unit_price']) ? round($quantity * (float) $data['unit_price'], 2) : null]);
            $p->increment('current_stock', $type === 'in' ? $quantity : -$quantity);
            $this->current_stock = $p->current_stock;

            return $m;
        });
    }
}
