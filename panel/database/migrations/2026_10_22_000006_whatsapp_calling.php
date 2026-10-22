<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// WhatsApp calls (Meta Calling API over SIP into our Asterisk, engine/voice_bot.py). calling_mode: off = callers get
// busy, test = only calling_test_numbers reach the bot, live = everyone. calling_bot_delay: seconds it rings first.
// call_logs.channel tells hotline calls from WhatsApp calls.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->string('calling_mode', 10)->default('off');
            $table->text('calling_test_numbers')->nullable();
            $table->unsignedSmallInteger('calling_bot_delay')->default(3);
        });
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('channel', 20)->default('hotline');
        });
    }

    public function down(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->dropColumn(['calling_mode', 'calling_test_numbers', 'calling_bot_delay']);
        });
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
