<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A customer MAC looked up on an OLT and not found there; not asked again for a while. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onu_mac_misses', function (Blueprint $table) {
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->string('client_mac');
            $table->timestamp('checked_at');
            $table->primary(['olt_id', 'client_mac']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_mac_misses');
    }
};
