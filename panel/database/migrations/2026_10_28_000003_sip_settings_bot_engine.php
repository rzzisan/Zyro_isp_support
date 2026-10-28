<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How the phone bot runs: live = Gemini Live (as before), cascade = speech → Claude → TTS (engine/voice_cascade.py). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->string('bot_engine', 10)->default('live');
            $table->string('cascade_scope', 10)->default('test');   // test = only bot_test_numbers, all = every call
        });
    }

    public function down(): void
    {
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->dropColumn(['bot_engine', 'cascade_scope']);
        });
    }
};
