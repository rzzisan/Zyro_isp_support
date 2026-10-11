<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which AI turns customers' voice messages into text first (its keys one by one, then the other voice AIs). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', fn (Blueprint $table) => $table->string('voice_provider', 30)->default('gemini'));
    }

    public function down(): void
    {
        Schema::table('bot_settings', fn (Blueprint $table) => $table->dropColumn('voice_provider'));
    }
};
