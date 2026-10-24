<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিলিং সংযোগ switches: the connection/MAC and the ONU come from our own MikroTik and OLTs first; billing may only
 * fill in what they don't have, while these are on. billing_fallbacks counts each time it did (per day and reason),
 * so the owner can see when a switch can go off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_connections', function (Blueprint $table) {
            $table->boolean('live_from_billing')->default(true);
            $table->boolean('onu_from_billing')->default(true);
        });
        Schema::create('billing_fallbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('day');                 // Bangladesh date
            $table->string('kind');              // live | onu
            $table->string('reason');            // no_router | no_mac | not_on_own_olt
            $table->unsignedInteger('count')->default(0);
            $table->unique(['company_id', 'day', 'kind', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_fallbacks');
        Schema::table('billing_connections', fn (Blueprint $table) => $table->dropColumn(['live_from_billing', 'onu_from_billing']));
    }
};
