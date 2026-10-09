<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the customer sends a voice message, also send the bot's reply as a voice message (Gemini TTS). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('voice_reply')->default(false);
            $table->string('voice_reply_voice', 30)->default('Kore');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', fn (Blueprint $table) => $table->dropColumn(['voice_reply', 'voice_reply_voice']));
    }
};
