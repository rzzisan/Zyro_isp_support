<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** One stock in / out of a StockProduct. */
class StockMovement extends Model
{
    public const TYPES = ['in' => 'স্টক ইন', 'out' => 'বিতরণ'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['moved_on' => 'date', 'quantity' => 'integer', 'unit_price' => 'decimal:2', 'total_price' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StockProduct::class, 'product_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Deletes the movement and puts the product's count back. */
    public function undo(): void
    {
        DB::transaction(function () {
            StockProduct::whereKey($this->product_id)->increment('current_stock', $this->type === 'in' ? -$this->quantity : $this->quantity);
            $this->delete();
        });
    }
}
