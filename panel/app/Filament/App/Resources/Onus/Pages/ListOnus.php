<?php

namespace App\Filament\App\Resources\Onus\Pages;

use App\Filament\App\Resources\Onus\OnuResource;
use App\Models\Onu;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListOnus extends ListRecords
{
    protected static string $resource = OnuResource::class;

    protected static ?string $title = 'ONU মনিটরিং';

    public function getSubheading(): ?string
    {
        $q = Onu::where('company_id', Filament::getTenant()->getKey());
        $total = (clone $q)->count();
        $online = (clone $q)->where('online', true)->count();
        $weak = (clone $q)->where('rx_dbm', '<', Onu::WEAK_DBM)->count();
        $last = (clone $q)->max('seen_at');

        return "মোট {$total} ONU · অনলাইন {$online} · অফলাইন ".($total - $online)." · দুর্বল power {$weak}"
            .' · OLT থেকে প্রতি ১০ মিনিটে আপডেট'.($last ? ' (শেষ '.\Illuminate\Support\Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('g:i A').')' : '');
    }
}
