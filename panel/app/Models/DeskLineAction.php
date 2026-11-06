<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 3: one line turned off/on by the Desk on the MikroTik itself, with what the router showed afterwards. */
class DeskLineAction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const ACTIONS = [
        'disable' => ['লাইন বন্ধ', 'danger'],
        'enable' => ['লাইন চালু', 'success'],
        'deactivate' => ['Inactive (secret বন্ধ)', 'warning'],
        'activate' => ['Active (secret চালু)', 'info'],
    ];

    public const STATUSES = [
        'running' => ['চলছে', 'gray'],
        'done' => ['হয়েছে, যাচাই মিলেছে', 'success'],
        'failed' => ['ব্যর্থ', 'danger'],
    ];

    public const REASONS = [
        'manual' => 'হাতে', 'test' => 'পরীক্ষা', 'unpaid' => 'বিল বাকি', 'paid' => 'বিল দিয়েছেন', 'partial' => 'আংশিক বাকি',
        'bot' => 'বট', 'extend' => 'সময় বাড়ানো', 'status' => 'Status বদল',
    ];

    public const CONTROL = [
        'off' => 'বন্ধ (Desk MikroTik-এ কিছু বদলায় না)',
        'test' => 'শুধু পরীক্ষার অ্যাকাউন্টে',
        'all' => 'সবার (শুধু হস্তান্তরের পর)',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'finished_at' => 'datetime', 'disabled_before' => 'boolean', 'disabled_after' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
