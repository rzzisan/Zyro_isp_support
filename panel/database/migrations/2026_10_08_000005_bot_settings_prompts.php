<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The company's own edit of the bots' built-in rules (null = the engine's built-in text). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->text('customer_prompt')->nullable();
            $table->text('technician_prompt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['customer_prompt', 'technician_prompt']);
        });
    }
};
