<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket lists (সমস্যার ধরন, কর্মী, ডিপার্টমেন্ট, Priority) kept in the Desk: with ticket_lists_in_billing off
 * (বিলিং সংযোগ page) billing's Sync no longer touches them and they are added / renamed / switched off in the Desk.
 * billing_options.desk marks an entry made in the Desk (its option_id is in the 900001+ range).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_connections', function (Blueprint $table) {
            $table->boolean('ticket_lists_in_billing')->default(true);
        });
        Schema::table('billing_options', function (Blueprint $table) {
            $table->boolean('desk')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('billing_options', fn (Blueprint $table) => $table->dropColumn('desk'));
        Schema::table('billing_connections', fn (Blueprint $table) => $table->dropColumn('ticket_lists_in_billing'));
    }
};
