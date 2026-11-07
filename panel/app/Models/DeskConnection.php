<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 5: one new connection the Desk sent to billing (engine/desk_connection.py); never holds the password. */
class DeskConnection extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const STATUSES = [
        'sending' => ['পাঠানো হচ্ছে', 'gray'],
        'done' => ['তৈরি, রাউটারে secret ঠিক', 'success'],
        'check' => ['তৈরি, রাউটার দেখুন', 'warning'],
        'unsure' => ['নিশ্চিত নয়, বিলিং-এ দেখুন', 'warning'],
        'failed' => ['তৈরি হয়নি', 'danger'],
    ];

    public const CONTROL = [
        'off' => 'বন্ধ (বিলিং-এ কিছু পাঠানো হয় না)',
        'test' => 'শুধু পরীক্ষার username',
        'all' => 'সব নতুন সংযোগ',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'finished_at' => 'datetime', 'form' => 'array', 'secret_disabled' => 'boolean',
            'monthly_bill' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
