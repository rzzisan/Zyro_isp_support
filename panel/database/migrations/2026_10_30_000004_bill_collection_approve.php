<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Members with the bill_approve permission: the engine approves the bill in billing right after saving it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_collections', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();    // approved in billing by the Desk right after saving
            $table->text('approve_error')->nullable();       // asked to approve but it stayed pending: why
        });
    }

    public function down(): void
    {
        Schema::table('bill_collections', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'approve_error']);
        });
    }
};
