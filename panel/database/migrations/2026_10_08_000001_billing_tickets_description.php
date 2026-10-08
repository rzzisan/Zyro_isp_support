<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The problem description typed when the ticket was opened (its first staff conversation entry in the billing software). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_tickets', function (Blueprint $table) {
            $table->text('description')->nullable();       // '' = fetched, ticket has none
        });
    }

    public function down(): void
    {
        Schema::table('billing_tickets', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
