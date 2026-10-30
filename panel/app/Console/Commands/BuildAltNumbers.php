<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\AltNumbers;
use Illuminate\Console\Command;

/** Nightly (zyro-alt-numbers.timer): every company's customers' other numbers, from their history (AltNumbers). */
class BuildAltNumbers extends Command
{
    protected $signature = 'customers:alt-numbers {--company= : only this company id}';

    protected $description = "Rebuild customers' other numbers (profile phone, tickets, WhatsApp, SMS)";

    public function handle(): int
    {
        foreach (Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))->pluck('id') as $id) {
            $this->line("company {$id}: ".AltNumbers::rebuild((int) $id).' new');
        }

        return self::SUCCESS;
    }
}
