<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A PPPoE session whose secret is on a block profile (billing "disable") is connected but has no internet (engine/ppp_sync.py). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppp_sessions', function (Blueprint $table) {
            $table->string('profile')->nullable();
            $table->boolean('blocked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ppp_sessions', function (Blueprint $table) {
            $table->dropColumn(['profile', 'blocked']);
        });
    }
};