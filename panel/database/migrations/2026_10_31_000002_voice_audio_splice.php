<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How a message with customer values ({name}, {due}, ...) is made into audio (engine/voice_tts.py): 'splice' makes the
// template's own words once for everybody and each value apart (one clip per amount, date or name, shared by every
// customer with it) and joins them; 'full' makes the whole text for every customer (smoother, costs much more).
// tts_model: the Gemini voice model for all messages ('auto': the newest first, the older one as the fallback).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->string('audio_mode', 10)->default('splice');
            $table->string('tts_model', 60)->default('auto');
        });
    }

    public function down(): void
    {
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->dropColumn(['audio_mode', 'tts_model']);
        });
    }
};
