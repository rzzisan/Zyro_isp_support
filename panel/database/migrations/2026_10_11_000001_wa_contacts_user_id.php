<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta now sends some WhatsApp users without a phone number, only a business-scoped user ID (BSUID, "BD.1234...")
 * and maybe a username. Such a chat keeps the BSUID in wa_number until the number is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->string('wa_user_id')->nullable();
            $table->string('wa_username')->nullable();
            $table->unique(['company_id', 'wa_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'wa_user_id']);
            $table->dropColumn(['wa_user_id', 'wa_username']);
        });
    }
};
