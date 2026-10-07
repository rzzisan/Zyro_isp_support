<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Technician extends Model
{
    protected $fillable = ['company_id', 'name', 'wa_number', 'active', 'note'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 01XXXXXXXXX / +8801XXXXXXXXX / 8801XXXXXXXXX -> 8801XXXXXXXXX (WhatsApp's form). */
    public static function normalize(string $number): string
    {
        $d = preg_replace('/\D/', '', $number);
        if (str_starts_with($d, '01') && strlen($d) === 11) {
            return '88'.$d;
        }

        return $d;
    }

    public function displayNumber(): string
    {
        return str_starts_with($this->wa_number, '880') ? '0'.substr($this->wa_number, 3) : $this->wa_number;
    }
}
