<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A desk agent linked the chat to a customer ID: the bot then always uses that line and never asks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->foreignId('customer_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('customer_set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_set_by');
            $table->dropColumn('customer_set_at');
        });
    }
};
