<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The owner can delete a sent message from Desk (Meta has no unsend, so the customer still has it): soft delete.
// wa_signature = false: the member's name ("- Name") is not added under their WhatsApp replies (set by the owner).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('company_user', function (Blueprint $table) {
            $table->boolean('wa_signature')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deleted_at');
        });
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn('wa_signature');
        });
    }
};
