<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Only technicians the owner allows may have the bot turn lines on/off; the log records which way. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->boolean('can_switch_lines')->default(false);
        });
        Schema::table('line_enables', function (Blueprint $table) {
            $table->string('action')->default('enable');     // enable | disable
        });
    }

    public function down(): void
    {
        Schema::table('technicians', fn (Blueprint $table) => $table->dropColumn('can_switch_lines'));
        Schema::table('line_enables', fn (Blueprint $table) => $table->dropColumn('action'));
    }
};
