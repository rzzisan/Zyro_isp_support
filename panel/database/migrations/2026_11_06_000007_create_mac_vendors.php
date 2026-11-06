<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAC address vendor list (IEEE OUI blocks, from Wireshark's manuf file): the brand of a customer's router
 * from the MAC MikroTik sees. Shared by all companies; filled by `php artisan mac-vendors:import`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mac_vendors', function (Blueprint $table) {
            $table->string('prefix', 9)->primary(); // upper-case hex, 6 (MA-L), 7 (MA-M) or 9 (MA-S) digits
            $table->string('vendor');                // full registered name
            $table->string('brand', 60);             // short display name (TP-Link, Tenda ...)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mac_vendors');
    }
};
