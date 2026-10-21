<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->text('bot_blocked_numbers')->nullable();                 // these callers never reach the bot
            $table->unsignedSmallInteger('bot_max_seconds')->default(240);   // the bot wraps up and hangs up by then
        });
        // why the bot ended or handed over the call (off-topic, time up, wants a person), shown flagged in the call log
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('bot_flag', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn('bot_flag');
        });
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->dropColumn(['bot_blocked_numbers', 'bot_max_seconds']);
        });
    }
};
