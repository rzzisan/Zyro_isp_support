<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer IDs left out of the Desk-vs-billing due comparison (house/office accounts like Century Link's 0001, Zisan
 * 5 Nov 2026). They stay in the ledger; only the match numbers skip them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->jsonb('match_exclude_codes')->nullable();
        });
        Schema::table('desk_customer_balances', function (Blueprint $table) {
            $table->boolean('excluded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('desk_customer_balances', fn (Blueprint $table) => $table->dropColumn('excluded'));
        Schema::table('billing_settings', fn (Blueprint $table) => $table->dropColumn('match_exclude_codes'));
    }
};
