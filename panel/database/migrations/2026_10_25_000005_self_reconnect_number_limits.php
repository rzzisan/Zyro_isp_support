<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How many lines one phone number may have the bot turn on a month (default 2), and the Owner's limits for single
 *  numbers: [{number, limit, note}]. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('self_reconnect_per_number')->default(2);
            $table->json('self_reconnect_number_limits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', fn (Blueprint $table) => $table->dropColumn(['self_reconnect_per_number', 'self_reconnect_number_limits']));
    }
};
