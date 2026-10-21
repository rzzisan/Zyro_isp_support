<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the hotline voice bot (engine/voice_bot.py): off, test (only the test numbers) or live (every caller)
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->string('bot_mode', 10)->default('off');
            $table->text('bot_test_numbers')->nullable();
            $table->unsignedSmallInteger('bot_delay')->default(20);   // seconds it rings before the bot answers
            $table->string('bot_model', 80)->nullable();
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->boolean('bot_answered')->default(false);
            $table->unsignedInteger('bot_seconds')->nullable();
            $table->string('recording_path')->nullable();     // under storage/app/call-recordings
            $table->text('transcript')->nullable();           // JSON [{who: caller|bot, text, at}]; text, as the test DB is SQL_ASCII
            $table->text('callback_reason')->nullable();      // the bot promised that an agent calls back
            $table->string('bot_error', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['bot_answered', 'bot_seconds', 'recording_path', 'transcript', 'callback_reason', 'bot_error']);
        });
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->dropColumn(['bot_mode', 'bot_test_numbers', 'bot_delay', 'bot_model']);
        });
    }
};
