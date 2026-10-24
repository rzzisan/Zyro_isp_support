<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Desk action on a ticket (engine/desk_tickets.py log_event writes it). */
class TicketEvent extends Model
{
    public const KINDS = ['open' => 'খোলা হয়েছে', 'assign' => 'অ্যাসাইন', 'edit' => 'এডিট', 'solve' => 'সমাধান'];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['detail' => 'array', 'created_at' => 'datetime'];
    }
}
