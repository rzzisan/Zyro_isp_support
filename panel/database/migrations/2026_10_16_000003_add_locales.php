<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel language per user (null = the company's default), and per company: the default panel language and the
 * language of what goes to customers (SMS, receipts, WhatsApp templates). Everything starts Bangla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('default_locale', 5)->default('bn');
            $table->string('customer_locale', 5)->default('bn');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['default_locale', 'customer_locale']);
        });
    }
};
