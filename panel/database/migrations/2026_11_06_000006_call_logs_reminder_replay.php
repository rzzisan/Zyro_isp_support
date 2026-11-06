<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// কল লগ: a hotline call back that heard the reminder of a missed reminder call again (engine/voice_ivr.py
// mark_callback_logs sets it, and backfills the call backs from before).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreignId('reminder_replay_id')->nullable()->constrained('voice_reminder_calls')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reminder_replay_id');
        });
    }
};
