<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Technician extends Model
{
    protected $fillable = ['company_id', 'name', 'wa_number', 'active', 'can_switch_lines', 'note',
        'billing_employee_id', 'billing_employee_name', 'notify_tickets'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'can_switch_lines' => 'boolean', 'notify_tickets' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The latest ticket message sent to this technician. */
    public function lastTicketNotification(): HasOne
    {
        return $this->hasOne(TicketNotification::class)->latestOfMany();
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
