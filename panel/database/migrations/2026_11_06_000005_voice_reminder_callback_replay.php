<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A customer who missed a reminder call calls the hotline back: their reminder message plays once, then the call goes
// on as usual (engine/voice_ivr.py callback_replay). callback_played_at marks the missed call as played back.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->boolean('callback_on')->default(true);
            $table->unsignedSmallInteger('callback_hours')->default(24);   // a call back this many hours after the missed call
        });
        Schema::table('voice_reminder_calls', function (Blueprint $table) {
            $table->timestamp('callback_played_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voice_reminder_calls', function (Blueprint $table) {
            $table->dropColumn('callback_played_at');
        });
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->dropColumn(['callback_on', 'callback_hours']);
        });
    }
};
