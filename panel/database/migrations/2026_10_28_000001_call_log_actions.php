<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the voice bot did on a call (engine/voice_bot.py): the steps in order and a one-word result. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->text('actions')->nullable();             // JSON [{at, kind, text, ok}]; text, as the test DB is SQL_ASCII
            $table->string('outcome', 20)->nullable()->index(); // solved|ticket|open_ticket|info|callback|unclear|hung_up
            $table->string('outcome_text', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['actions', 'outcome', 'outcome_text']);
        });
    }
};
