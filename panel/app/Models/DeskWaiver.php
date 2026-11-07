<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** বিল মাফ (billing phase 4c): a month whose bill the Desk waives (shadow while billing is the master). */
class DeskWaiver extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const STATUSES = [
        'shadow' => ['মাফ হত (ছায়া)', 'info'],
        'pending' => ['লগ আসা বাকি', 'warning'],
        'applied' => ['খাতায় মাফ', 'success'],
    ];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'amount' => 'decimal:2', 'created_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DeskCustomer::class, 'customer_ref');
    }
}
