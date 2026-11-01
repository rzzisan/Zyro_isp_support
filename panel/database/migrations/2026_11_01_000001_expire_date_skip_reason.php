<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a bulk "মেয়াদ বদল" row was left off by the rules (engine/expire_dates.py exclusions): day_changed (billing's
 * Expire Date set again after the payment, e.g. by hand), paid_later (a newer bill), advance. NULL = ticked off by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expire_date_changes', function (Blueprint $table) {
            $table->string('skip_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expire_date_changes', fn (Blueprint $table) => $table->dropColumn('skip_reason'));
    }
};
