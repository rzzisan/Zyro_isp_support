<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each member's own billing-software login: tickets they open or assign from the panel are made as them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('billing_username')->nullable();
            $table->text('billing_password')->nullable();       // encrypted with APP_KEY
            $table->timestamp('billing_checked_at')->nullable();
            $table->boolean('billing_check_ok')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn(['billing_username', 'billing_password', 'billing_checked_at', 'billing_check_ok']);
        });
    }
};
